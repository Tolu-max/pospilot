<?php

namespace App\Http\Controllers;

use App\Models\GmailConnection;
use App\Models\GmailStatementMessage;
use App\Models\Provider;
use App\Services\Gmail\GmailStatementConnector;
use App\Services\GmailIntegrationConfiguration;
use App\Services\GmailStatementImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GmailStatementController extends Controller
{
    public function sync(Request $request, GmailStatementConnector $connector): JsonResponse
    {
        return $this->runSync($request, $connector, false);
    }

    public function importOlder(Request $request, GmailStatementConnector $connector): JsonResponse
    {
        return $this->runSync($request, $connector, true);
    }

    private function runSync(Request $request, GmailStatementConnector $connector, bool $includeOlder): JsonResponse
    {
        abort_unless(config('gmail_statement.enabled'), 404);
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $connection = GmailConnection::where('agent_profile_id', $agent->id)
            ->whereIn('status', ['connected', 'sync_error'])
            ->first();
        abort_unless($connection, 422, 'Connect Gmail before checking for statements.');
        abort_unless(count($agent->selected_provider_slugs ?? []) > 0, 422, 'Choose at least one provider before checking for statements.');

        try {
            $results = $connector->sync($connection, $includeOlder);
        } catch (\Throwable) {
            if (! in_array($connection->fresh()?->status, ['disconnected', 'permission_expired'], true)) {
                $connection->update(['status' => 'sync_error', 'last_sync_status' => 'failed', 'last_error_code' => 'gmail_sync_failed']);
            }

            return response()->json(['message' => 'POSPilot could not check Gmail. Reconnect or try again later.'], 502);
        }

        return response()->json(['status' => $connection->fresh()?->status ?? 'disconnected', ...$results]);
    }

    public function status(Request $request, GmailIntegrationConfiguration $configuration): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $connection = GmailConnection::where('agent_profile_id', $agent->id)->first();
        $messages = GmailStatementMessage::where('agent_profile_id', $agent->id);

        return response()->json([
            'enabled' => (bool) config('gmail_statement.enabled'),
            'configured' => $configuration->isConfigured(),
            'connected' => $connection !== null && $connection->status !== 'disconnected',
            'status' => $connection?->status ?? 'disconnected',
            'gmail_address_masked' => $connection?->gmail_address_masked,
            'connected_at' => $connection?->connected_at,
            'last_synced_at' => $connection?->last_synced_at,
            'last_sync_status' => $connection?->last_sync_status,
            'last_error_code' => $connection?->last_error_code,
            'selected_provider_slugs' => $agent->selected_provider_slugs ?? [],
            'provider_rules' => $agent->statement_sender_rules ?? [],
            'statement_counts' => [
                'needs_setup' => (clone $messages)->where('status', 'needs_setup')->count(),
                'unsupported_format' => (clone $messages)->where('status', 'unsupported_format')->count(),
                'processed' => (clone $messages)->where('status', 'processed')->count(),
            ],
        ]);
    }

    public function saveRules(Request $request): JsonResponse
    {
        abort_unless(config('gmail_statement.enabled'), 404);
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $validated = $request->validate([
            'selected_provider_slugs' => ['required', 'array', 'min:1', 'max:3'],
            'selected_provider_slugs.*' => ['required', 'string', 'distinct', Rule::in(['moniepoint', 'opay', 'palmpay'])],
            'provider_rules' => ['sometimes', 'array'],
            'provider_rules.*.sender_email' => ['nullable', 'email', 'max:255'],
        ]);
        $rules = [];
        foreach ($validated['selected_provider_slugs'] as $slug) {
            $provider = Provider::where('slug', $slug)->first();
            abort_unless($provider, 422, 'Select a supported provider.');
            $sender = strtolower(trim((string) data_get($validated, 'provider_rules.'.$slug.'.sender_email', '')));
            if ($sender !== '' && ! filter_var($sender, FILTER_VALIDATE_EMAIL)) {
                abort(response()->json(['message' => 'Check the sender email address and try again.', 'errors' => ['provider_rules.'.$slug.'.sender_email' => ['Enter a valid sender email or leave this field empty.']]], 422));
            }
            $rules[$slug] = ['sender_email' => $sender !== '' ? $sender : null];
        }
        $senders = array_values(array_filter(array_column(array_values($rules), 'sender_email')));
        abort_unless(count($senders) === count(array_unique($senders)), 422, 'Use a distinct sender email for each provider so statements cannot be assigned to the wrong provider.');
        $agent->update(['selected_provider_slugs' => $validated['selected_provider_slugs'], 'statement_sender_rules' => $rules]);
        GmailConnection::where('agent_profile_id', $agent->id)->update(['provider_rules' => $rules]);

        return response()->json(['selected_provider_slugs' => $agent->selected_provider_slugs, 'provider_rules' => $agent->statement_sender_rules]);
    }

    public function index(Request $request): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $messages = GmailStatementMessage::where('agent_profile_id', $agent->id)
            ->with('provider:id,name,slug')
            ->whereIn('status', ['needs_setup', 'unsupported_format', 'unsupported_schema', 'rejected', 'failed', 'needs_setup_expired', 'processed'])
            ->latest('received_at')->limit(50)->get()
            ->map(fn (GmailStatementMessage $message): array => [
                'id' => $message->id,
                'provider' => $message->provider?->only(['name', 'slug']),
                'file_type' => $message->file_type,
                'headers' => $message->status === 'needs_setup' ? $message->headers : [],
                'masked_account_identifier' => $message->masked_account_identifier,
                'status' => $message->status,
                'failure_code' => $this->safeFailureCode($message->failure_code),
                'rows_imported' => $message->rows_imported,
                'rows_duplicate' => $message->rows_duplicate,
                'rows_failed' => $message->rows_failed,
                'received_at' => $message->received_at,
                'can_map' => $message->status === 'needs_setup' && filled($message->temporary_file_path),
            ]);

        return response()->json(['data' => $messages]);
    }

    public function saveMapping(Request $request, GmailStatementMessage $message, GmailStatementImportService $imports): JsonResponse
    {
        abort_unless(config('gmail_statement.enabled'), 404);
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent && $message->agent_profile_id === $agent->id, 404);
        abort_unless($message->status === 'needs_setup', 422, 'This statement is not waiting for setup.');
        $fields = ['external_reference', 'amount', 'customer_charge', 'provider_fee', 'transaction_status', 'transaction_at', 'transaction_type', 'merchant_identifier', 'business_identifier', 'terminal_identifier', 'provider_account_identifier', 'settlement_reference'];
        $rules = ['terminal_id' => ['required', 'integer']];
        foreach ($fields as $field) {
            $rules['column_mapping.'.$field] = ['nullable', 'string', 'max:255'];
        }
        $validated = $request->validate($rules);
        $profile = $imports->saveMapping($message, (array) ($validated['column_mapping'] ?? []), (int) $validated['terminal_id']);
        $imports->process($message->fresh(), $profile);

        return response()->json([
            'status' => 'processed',
            'statement_id' => $message->id,
            'rows_imported' => $message->fresh()->rows_imported,
            'rows_duplicate' => $message->fresh()->rows_duplicate,
            'rows_failed' => $message->fresh()->rows_failed,
        ]);
    }

    private function safeFailureCode(?string $code): ?string
    {
        return match ($code) {
            'spreadsheet_format_not_enabled' => 'unsupported_format',
            'pdf_not_supported' => 'pdf_not_supported',
            'xls_not_supported' => 'xls_not_supported',
            'gmail_disconnected' => 'gmail_disconnected',
            'attachment_too_large' => 'attachment_too_large',
            'unsupported_schema' => 'unsupported_schema',
            'attachment_read_failed' => 'attachment_read_failed',
            'invalid_headers' => 'unsupported_schema',
            'mapping_expired' => 'mapping_expired',
            'row_limit_exceeded' => 'row_limit_exceeded',
            'attachment_expired' => 'attachment_expired',
            'terminal_mapping_invalid' => 'terminal_mapping_invalid',
            default => null,
        };
    }
}
