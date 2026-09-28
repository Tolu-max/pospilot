<?php

namespace App\Http\Controllers;

use App\Contracts\GmailCredentialStore;
use App\Exceptions\GmailCredentialStoreUnavailable;
use App\Jobs\SyncConnectedGmailStatements;
use App\Models\GmailConnection;
use App\Services\GmailIntegrationConfiguration;
use App\Services\GoogleGmailClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class GmailOAuthController extends Controller
{
    public function connect(Request $request, GmailIntegrationConfiguration $configuration): RedirectResponse
    {
        abort_unless(config('gmail_statement.enabled'), 404);
        abort_unless($configuration->isConfigured(), 503, 'Gmail connection is not configured.');
        abort_unless($request->user()->businessAgentProfile(), 404);

        $state = Str::random(64);
        $request->session()->put('gmail_oauth_state', Crypt::encryptString($state));
        $request->session()->put('gmail_oauth_agent_id', $request->user()->businessAgentProfile()->id);

        $query = http_build_query([
            'client_id' => config('gmail_statement.client_id'),
            'redirect_uri' => config('gmail_statement.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('gmail_statement.scope'),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request, GmailCredentialStore $credentials, GoogleGmailClient $gmail, GmailIntegrationConfiguration $configuration): RedirectResponse
    {
        abort_unless(config('gmail_statement.enabled'), 404);
        abort_unless($configuration->isConfigured(), 503, 'Gmail connection is not configured.');

        $expectedState = $request->session()->pull('gmail_oauth_state');
        $agentId = $request->session()->pull('gmail_oauth_agent_id');
        $providedState = $request->string('state')->toString();
        $code = $request->string('code')->toString();

        if (! is_string($expectedState) || ! is_numeric($agentId)
            || (int) $agentId !== (int) $request->user()->businessAgentProfile()?->id
            || $providedState === '') {
            return redirect('/dashboard?screen=providers')->with('gmail_error', 'Google connection could not be verified. Please try again.');
        }

        try {
            $matchesState = hash_equals(Crypt::decryptString($expectedState), $providedState);
        } catch (Throwable) {
            $matchesState = false;
        }

        if (! $matchesState) {
            return redirect('/dashboard?screen=providers')->with('gmail_error', 'Google connection could not be verified. Please try again.');
        }

        if ($request->filled('error')) {
            return redirect('/dashboard?screen=providers')->with('gmail_error', 'Gmail access was not granted.');
        }
        if ($code === '') {
            return redirect('/dashboard?screen=providers')->with('gmail_error', 'Google connection could not be verified. Please try again.');
        }

        $connection = null;
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('gmail_statement.client_id'),
                'client_secret' => config('gmail_statement.client_secret'),
                'redirect_uri' => config('gmail_statement.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);
            $tokens = $response->json();
            abort_unless($response->successful() && is_string($tokens['access_token'] ?? null), 422);
            abort_unless(in_array(config('gmail_statement.scope'), explode(' ', (string) ($tokens['scope'] ?? '')), true), 422);

            $emailAddress = $gmail->emailAddressForAccessToken($tokens['access_token']);
            $connection = GmailConnection::firstOrCreate(
                ['agent_profile_id' => $request->user()->businessAgentProfile()->id],
                ['status' => 'connecting', 'provider_rules' => $request->user()->businessAgentProfile()->statement_sender_rules ?? []],
            );
            $existingRefreshToken = false;
            try {
                $existingCredentials = $credentials->retrieve($connection);
                $existingRefreshToken = is_string($existingCredentials['refresh_token'] ?? null)
                    && trim($existingCredentials['refresh_token']) !== '';
            } catch (GmailCredentialStoreUnavailable) {
                $existingRefreshToken = false;
            }
            abort_unless($existingRefreshToken || (is_string($tokens['refresh_token'] ?? null) && trim($tokens['refresh_token']) !== ''), 422);
            $credentials->store($connection, [
                'access_token' => $tokens['access_token'],
                'refresh_token' => is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : null,
                'expires_in' => (int) ($tokens['expires_in'] ?? 3600),
            ]);
            $localPart = strstr($emailAddress, '@', true) ?: '';
            $domain = substr(strstr($emailAddress, '@') ?: '', 1);
            $connection->update([
                'status' => 'sync_queued',
                'gmail_address_masked' => mb_substr($localPart, 0, 1).'••••@'.$domain,
                'connected_at' => now(),
                'disconnected_at' => null,
                'last_error_code' => null,
                'last_sync_status' => 'queued',
            ]);
        } catch (GmailCredentialStoreUnavailable) {
            if ($connection?->status === 'connecting') {
                $connection->update(['status' => 'disconnected']);
            }

            return redirect('/dashboard?screen=providers')->with('gmail_error', 'Secure Gmail token storage is not configured for this environment.');
        } catch (Throwable) {
            if ($connection?->status === 'connecting') {
                $connection->update(['status' => 'disconnected']);
            }

            return redirect('/dashboard?screen=providers')->with('gmail_error', 'Gmail could not be connected. Check the Google setup and try again.');
        }

        try {
            SyncConnectedGmailStatements::dispatch($connection->id);
        } catch (Throwable) {
            $connection->update(['status' => 'connected', 'last_sync_status' => 'failed', 'last_error_code' => 'gmail_sync_queue_failed']);
        }

        return redirect('/dashboard?screen=providers')
            ->with('gmail_status', 'Gmail connected. POSPilot is checking recent statement emails.')
            ->with('analytics_event', ['name' => 'gmail_connected', 'id' => (string) Str::uuid()]);
    }

    public function disconnect(Request $request, GmailCredentialStore $credentials): RedirectResponse
    {
        $connection = GmailConnection::where('agent_profile_id', $request->user()->businessAgentProfile()?->id)->first();
        if ($connection) {
            try {
                $tokens = $credentials->retrieve($connection);
                Http::asForm()->connectTimeout(3)->timeout(5)->post('https://oauth2.googleapis.com/revoke', ['token' => $tokens['refresh_token'] ?: $tokens['access_token']]);
            } catch (Throwable) {
                // Delete locally even if Google revocation is temporarily unavailable.
            }

            try {
                $credentials->forget($connection);
            } catch (Throwable) {
                return redirect('/dashboard?screen=providers')->with('gmail_error', 'Gmail could not be fully disconnected. Please retry.');
            }

            $connection->messages()->whereNotNull('temporary_file_path')->get()->each(function ($message): void {
                Storage::disk('local')->delete($message->temporary_file_path);
                $message->update(['temporary_file_path' => null, 'status' => 'needs_setup_expired', 'failure_code' => 'gmail_disconnected']);
            });
            $connection->update([
                'status' => 'disconnected',
                'last_sync_status' => 'disconnected',
                'last_error_code' => null,
                'disconnected_at' => now(),
            ]);
        }

        return redirect('/dashboard?screen=providers')->with('gmail_status', 'Gmail disconnected. POSPilot stopped statement discovery.');
    }
}
