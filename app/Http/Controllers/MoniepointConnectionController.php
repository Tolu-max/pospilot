<?php

namespace App\Http\Controllers;

use App\Contracts\ProviderSecretStore;
use App\Enums\ProviderConnectionStatus;
use App\Enums\ProviderConnectionType;
use App\Exceptions\ProviderSecretStoreUnavailable;
use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\ProviderConnection;
use App\Services\MoniepointConnectionService;
use App\Services\SecurityEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MoniepointConnectionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $connection = $this->connection($agent);

        return response()->json($this->safeStatus($agent, $connection));
    }

    public function store(Request $request, ProviderSecretStore $secrets, SecurityEventRecorder $events): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string', 'max:4096'],
            'webhook_secret' => ['required', 'string', 'max:4096'],
            'business_id' => ['required', 'string', 'max:100', 'regex:/^[0-9]+$/'],
        ]);
        $agent = $this->agent($request);
        $provider = Provider::query()->where('slug', 'moniepoint')->firstOrFail();
        $existing = $agent->providerConnections()->where('provider_id', $provider->id)->where('connection_type', ProviderConnectionType::Api)->first();
        $connection = $existing ?? new ProviderConnection;
        try {
            DB::transaction(function () use ($connection, $agent, $provider, $validated, $existing, $secrets): void {
                $connection->fill([
                    'agent_profile_id' => $agent->id,
                    'provider_id' => $provider->id,
                    'connection_type' => ProviderConnectionType::Api,
                    'connection_status' => ProviderConnectionStatus::Inactive,
                    'provider_merchant_identifier' => $validated['business_id'],
                    'last_sync_status' => 'pending_verification',
                    'last_sync_error' => null,
                ]);
                $connection->save();
                $secretValues = ['api_key' => $validated['api_key'], 'webhook_secret' => $validated['webhook_secret']];

                if ($existing && $connection->secret_reference !== null) {
                    $secrets->rotate($connection, $secretValues);
                } else {
                    $secrets->store($connection, $secretValues);
                }
            });
        } catch (ProviderSecretStoreUnavailable) {
            return response()->json(['message' => 'Provider secret storage is not configured.'], 503);
        }

        Log::notice('provider_connection_event', [
            'event' => $existing ? 'credential_updated' : 'connection_created',
            'provider' => 'moniepoint',
            'provider_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
        ]);
        $events->record($request->user(), 'provider_credentials_updated', $request, ['provider' => 'moniepoint']);

        return response()->json($this->safeStatus($agent, $connection), $existing ? 200 : 201);
    }

    public function test(Request $request, MoniepointConnectionService $service, SecurityEventRecorder $events): JsonResponse
    {
        $agent = $this->agent($request);
        $connection = $this->connection($agent);

        abort_unless($connection, 404);
        $result = $service->test($connection);

        if ($result['verified']) {
            $events->record($request->user(), 'provider_connected', $request, ['provider' => 'moniepoint']);
        }

        return response()->json([
            'connected' => $result['verified'],
            'status' => $result['status'],
            'error' => $result['error'],
            'connection' => $this->safeStatus($agent, $connection->refresh()),
        ], $result['verified'] ? 200 : ($result['status'] === 'secure_store_unavailable' ? 503 : 422));
    }

    public function destroy(Request $request, ProviderSecretStore $secrets, SecurityEventRecorder $events): JsonResponse
    {
        $agent = $this->agent($request);
        $connection = $this->connection($agent);

        if ($connection) {
            try {
                DB::transaction(function () use ($connection, $secrets): void {
                    $secrets->revoke($connection);
                    $connection->update([
                        'connection_status' => ProviderConnectionStatus::Inactive,
                        'last_sync_status' => 'disconnected',
                        'last_sync_error' => null,
                    ]);
                });
            } catch (ProviderSecretStoreUnavailable) {
                return response()->json(['message' => 'Provider secret storage is not configured.'], 503);
            }
            Log::notice('provider_connection_event', [
                'event' => 'connection_disconnected',
                'provider' => 'moniepoint',
                'provider_connection_id' => $connection->id,
                'agent_profile_id' => $agent->id,
            ]);
            $events->record($request->user(), 'provider_disconnected', $request, ['provider' => 'moniepoint']);
        }

        return response()->json(['connected' => false, 'status' => 'disconnected']);
    }

    private function agent(Request $request): AgentProfile
    {
        return AgentProfile::query()->where('user_id', $request->user()->id)->firstOrFail();
    }

    private function connection(AgentProfile $agent): ?ProviderConnection
    {
        return $agent->providerConnections()
            ->whereHas('provider', fn ($query) => $query->where('slug', 'moniepoint'))
            ->where('connection_type', ProviderConnectionType::Api)
            ->first();
    }

    /** @return array<string, mixed> */
    private function safeStatus(AgentProfile $agent, ?ProviderConnection $connection): array
    {
        $provider = Provider::query()->where('slug', 'moniepoint')->first();

        return [
            'connected' => $connection?->connection_status === ProviderConnectionStatus::Active,
            'connection_type' => $connection?->connection_type?->value,
            'status' => $connection?->last_sync_status ?? 'not_connected',
            'error' => $connection?->last_sync_error,
            'last_synced_at' => $connection?->last_synced_at,
            'last_webhook_at' => $connection?->last_webhook_at,
            'terminal_count' => $provider ? $agent->terminals()->where('provider_id', $provider->id)->count() : 0,
        ];
    }
}
