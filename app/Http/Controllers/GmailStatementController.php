<?php

namespace App\Http\Controllers;

use App\Jobs\SyncConnectedGmailStatements;
use App\Models\GmailConnection;
use App\Models\GmailStatementMessage;
use App\Models\Provider;
use App\Services\GmailIntegrationConfiguration;
use App\Services\GmailStatementImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GmailStatementController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        abort_unless(config('gmail_statement.enabled'), 404);
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $connection = GmailConnection::where('agent_profile_id', $agent->id)
            ->whereIn('status', ['connected', 'sync_error', 'sync_queued'])
            ->first();
        abort_unless($connection, 422, 'Connect Gmail before syncing statements.');
        abort_unless(count($agent->selected_provider_slugs ?? []) > 0, 422, 'Choose at least one provider in connection settings.');

        if ($connection->status !== 'sync_queued') {
            $connection->update(['status' => 'sync_queued', 'last_sync_status' => 'queued', 'last_error_code' => null]);
        }
        SyncConnectedGmailStatements::dispatch($connection->id);

        return response()->json(['status' => 'sync_queued'], 202);
    }

    public function importOlder(Request $request): JsonResponse
    {
        abort_unless(config('gmail_statement.enabled'), 404);
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $connection = GmailConnection::where('agent_profile_id', $agent->id)
            ->whereIn('status', ['connected', 'sync_error'])
            ->first();
        abort_unless($connection, 422, 'Connect Gmail before checking for statements.');
        abort_unless(count($agent->selected_provider_slugs ?? []) > 0, 422, 'Choose at least one provider before checking for statements.');

        $connection->update(['status' => 'sync_queued', 'last_sync_status' => 'queued', 'last_error_code' => null]);
        SyncConnectedGmailStatements::dispatch($connection->id, true);

        return response()->json(['status' => 'sync_queued'], 202);
    }

    public function status(Request $request, GmailIntegrationConfiguration $configuration): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $connection = GmailConnection::where('agent_profile_id', $agent->id)->first();
        $messages = GmailStatementMessage::where('agent_profile_id', $agent->id);
        $processedPosStatements = (clone $messages)
            ->where('status', 'processed')
            ->where(function ($query): void {
                $query->whereNull('failure_code')->orWhere('failure_code', '!=', 'no_pos_activity');
            })
            ->whereDoesntHave('mappingProfile', fn ($query) => $query->whereJsonContains('column_mapping->_activity_scope', 'personal_wallet'));
        $processedActivity = (clone $processedPosStatements)->get(['activity_summary'])->reduce(function (array $totals, GmailStatementMessage $message): array {
            $totals['pos_rows_found'] += (int) data_get($message->activity_summary, 'pos', 0);
            $totals['wallet_rows_excluded'] += (int) data_get($message->activity_summary, 'wallet', 0);

            return $totals;
        }, ['pos_rows_found' => 0, 'wallet_rows_excluded' => 0]);

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
                'processed' => (clone $processedPosStatements)->count(),
                'rows_imported' => (clone $processedPosStatements)->sum('rows_imported'),
                'rows_duplicate' => (clone $processedPosStatements)->sum('rows_duplicate'),
                'rows_failed' => (clone $processedPosStatements)->sum('rows_failed'),
                ...$processedActivity,
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
            'provider_rules.*.account_type' => [Rule::in(['business_pos', 'personal_dedicated_pos', 'mixed_personal_pos'])],
        ]);
        $rules = [];
        foreach ($validated['selected_provider_slugs'] as $slug) {
            $provider = Provider::where('slug', $slug)->first();
            abort_unless($provider, 422, 'Select a supported provider.');
            $accountType = data_get($validated, 'provider_rules.'.$slug.'.account_type');
            abort_unless(in_array($accountType, ['business_pos', 'personal_dedicated_pos', 'mixed_personal_pos'], true), 422, 'Choose how this provider account is used.');
            $sender = strtolower(trim((string) data_get($validated, 'provider_rules.'.$slug.'.sender_email', '')));
            if ($sender !== '' && ! filter_var($sender, FILTER_VALIDATE_EMAIL)) {
                abort(response()->json(['message' => 'Check the sender email address and try again.', 'errors' => ['provider_rules.'.$slug.'.sender_email' => ['Enter a valid sender email or leave this field empty.']]], 422));
            }
            $rules[$slug] = ['sender_email' => $sender !== '' ? $sender : null, 'account_type' => $accountType];
        }
        $senders = array_values(array_filter(array_column(array_values($rules), 'sender_email')));
        abort_unless(count($senders) === count(array_unique($senders)), 422, 'Use a distinct sender email for each provider so statements cannot be assigned to the wrong provider.');
        $agent->update(['selected_provider_slugs' => $validated['selected_provider_slugs'], 'statement_sender_rules' => $rules]);
        $connection = GmailConnection::where('agent_profile_id', $agent->id)->first();
        $connection?->update(['provider_rules' => $rules]);
        if ($connection && $connection->status === 'connected') {
            $connection->update(['status' => 'sync_queued', 'last_sync_status' => 'queued', 'last_error_code' => null]);
            SyncConnectedGmailStatements::dispatch($connection->id);
        }

        return response()->json(['selected_provider_slugs' => $agent->selected_provider_slugs, 'provider_rules' => $agent->statement_sender_rules]);
    }

    public function index(Request $request): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $messages = GmailStatementMessage::where('agent_profile_id', $agent->id)
            ->with(['provider:id,name,slug', 'mappingProfile:id,column_mapping,provider_account_id,account_type', 'mappingProfile.providerAccount:id,display_name,account_type'])
            ->whereIn('status', ['needs_setup', 'unsupported_format', 'unsupported_schema', 'rejected', 'failed', 'needs_setup_expired', 'processed'])
            ->orderByRaw("CASE WHEN status = 'needs_setup' THEN 0 WHEN status IN ('unsupported_format', 'unsupported_schema', 'rejected', 'failed', 'needs_setup_expired') THEN 1 ELSE 2 END")
            ->latest('updated_at')->latest('received_at')->latest('id')->limit(50)->get()
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
                'activity_summary' => $message->activity_summary,
                'selected_activity_patterns' => (array) ($message->mappingProfile?->column_mapping['_pos_activity_patterns'] ?? []),
                'excluded_patterns' => $message->mappingProfile?->excluded_patterns ?? [],
                'account_type' => $message->mappingProfile?->account_type,
                'provider_account' => $message->mappingProfile?->providerAccount?->only(['id', 'display_name', 'account_type']),
                'activity_classification_status' => $message->mappingProfile?->classification_status,
                'provider_account_type' => data_get($message->connection?->provider_rules, ($message->provider?->slug ?? '').'.account_type', 'mixed_personal_pos'),
                'pattern_classifications' => collect((array) data_get($message->activity_summary, 'patterns', []))
                    ->mapWithKeys(function (array $pattern) use ($message): array {
                        $id = (string) ($pattern['id'] ?? '');
                        $knownPos = (array) ($message->mappingProfile?->known_pos_transaction_patterns ?? []);
                        $excluded = (array) ($message->mappingProfile?->excluded_patterns ?? []);

                        return [$id => in_array($id, $knownPos, true)
                            ? 'pos'
                            : (in_array($id, $excluded, true) || ! (bool) ($pattern['selectable'] ?? true) ? 'personal_excluded' : 'needs_review')];
                    })->all(),
                'received_at' => $message->received_at,
                'can_map' => $message->status === 'needs_setup'
                    && ! in_array($message->failure_code, ['personal_wallet_statement', 'pdf_no_pos_rows'], true)
                    && filled($message->temporary_file_path),
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
        if ($message->failure_code === 'personal_wallet_statement') {
            abort(response()->json(['message' => 'This statement does not contain verified POS terminal activity. No statement rows were imported.'], 422));
        }
        $rules = [
            'activity_scope' => ['sometimes', Rule::in(['pos_terminal'])],
            'terminal_id' => ['nullable', 'integer'],
            'activity_patterns' => ['sometimes', 'array', 'max:100'],
            'activity_patterns.*' => ['required', 'string', 'distinct', 'size:64'],
            'activity_classifications' => ['sometimes', 'array', 'max:100'],
            'activity_classifications.*' => ['required', Rule::in(['pos', 'personal_excluded', 'needs_review'])],
            'account_type' => ['sometimes', Rule::in(['business_pos', 'personal_dedicated_pos', 'mixed_personal_pos'])],
        ];
        foreach ($fields as $field) {
            $rules['column_mapping.'.$field] = ['nullable', 'string', 'max:255'];
        }
        $validated = $request->validate($rules);
        $allPatterns = (array) data_get($message->activity_summary, 'patterns', []);
        $allowedPatterns = array_column(array_filter($allPatterns, fn (array $pattern): bool => (bool) ($pattern['selectable'] ?? true)), 'id');
        $fixedExcludedPatterns = array_column(array_filter($allPatterns, fn (array $pattern): bool => ! (bool) ($pattern['selectable'] ?? true)), 'id');
        foreach ((array) ($validated['activity_patterns'] ?? []) as $pattern) {
            abort_unless(in_array($pattern, $allowedPatterns, true), 422, 'Choose a pattern shown for this statement.');
        }
        $activityClassifications = (array) ($validated['activity_classifications'] ?? []);
        if ($activityClassifications !== []) {
            foreach ($activityClassifications as $pattern => $classification) {
                abort_unless(in_array($pattern, $allowedPatterns, true), 422, 'Choose a pattern shown for this statement.');
                abort_unless($classification !== 'needs_review', 422, 'Classify each unclear transaction pattern before importing.');
            }
            $activityPatterns = array_keys(array_filter($activityClassifications, fn (string $classification): bool => $classification === 'pos'));
            $excludedPatterns = [...array_keys(array_filter($activityClassifications, fn (string $classification): bool => $classification === 'personal_excluded')), ...$fixedExcludedPatterns];
            abort_unless(array_diff($allowedPatterns, array_keys($activityClassifications)) === [], 422, 'Classify each unclear transaction pattern before importing.');
        } else {
            $activityPatterns = (array) ($validated['activity_patterns'] ?? []);
            $excludedPatterns = [...array_values(array_diff($allowedPatterns, $activityPatterns)), ...$fixedExcludedPatterns];
        }
        $connection = $message->connection;
        $defaultAccountType = (string) data_get($connection?->provider_rules, ($message->provider?->slug ?? '').'.account_type', 'mixed_personal_pos');
        $accountType = (string) ($validated['account_type'] ?? $defaultAccountType);
        $profile = $imports->saveMapping($message, (array) ($validated['column_mapping'] ?? []), isset($validated['terminal_id']) ? (int) $validated['terminal_id'] : null, $validated['activity_scope'] ?? 'pos_terminal', $activityPatterns, array_column($allPatterns, 'id'), $excludedPatterns, $accountType);
        $imports->process($message->fresh(), $profile);
        $excludedAmbiguousRows = collect($allPatterns)
            ->reject(fn (array $pattern): bool => in_array($pattern['id'] ?? null, $activityPatterns, true))
            ->sum(fn (array $pattern): int => (int) ($pattern['rows'] ?? 0));

        return response()->json([
            'status' => 'processed',
            'statement_id' => $message->id,
            'rows_imported' => $message->fresh()->rows_imported,
            'rows_duplicate' => $message->fresh()->rows_duplicate,
            'rows_failed' => $message->fresh()->rows_failed,
            'rows_excluded' => max(0, (int) data_get($message->activity_summary, 'wallet', 0)) + $excludedAmbiguousRows,
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
            'pdf_scanned_unsupported' => 'pdf_scanned_unsupported',
            'pdf_page_limit_exceeded' => 'pdf_page_limit_exceeded',
            'pdf_parse_failed' => 'pdf_parse_failed',
            'pdf_no_pos_rows' => 'pdf_no_pos_rows',
            'pdf_no_activity_rows' => 'pdf_no_pos_rows',
            'activity_classification_required' => 'activity_classification_required',
            'no_pos_activity' => 'no_pos_activity',
            'personal_wallet_statement' => 'personal_wallet_statement',
            'pdf_table_unrecognized' => 'unsupported_schema',
            'duplicate_attachment' => 'duplicate_attachment',
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
