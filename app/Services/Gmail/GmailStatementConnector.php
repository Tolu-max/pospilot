<?php

namespace App\Services\Gmail;

use App\Models\GmailConnection;
use App\Models\GmailStatementMessage;
use App\Models\Provider;
use App\Models\StatementMappingProfile;
use App\Models\Terminal;
use App\Services\GmailStatementImportService;
use App\Services\GoogleGmailClient;
use App\Services\XlsxStatementReader;
use Carbon\CarbonImmutable;
use RuntimeException;

final class GmailStatementConnector
{
    public function __construct(
        private readonly GoogleGmailClient $gmail,
        private readonly GmailStatementImportService $imports,
        private readonly XlsxStatementReader $xlsx,
        private readonly GmailPdfStatementReader $pdfReader,
        private readonly PdfStatementTableReader $pdfTables,
    ) {}

    /** @return array{candidates_checked:int,statements_found:int} */
    public function sync(GmailConnection $connection, bool $includeOlder = false): array
    {
        $claimed = GmailConnection::whereKey($connection->id)
            ->whereIn('status', ['connected', 'sync_error', 'sync_queued'])
            ->update(['status' => 'syncing']);
        if ($claimed !== 1) {
            return ['candidates_checked' => 0, 'statements_found' => 0];
        }
        $connection->refresh();
        $this->backfillMessageFingerprints($connection);
        $rules = (array) $connection->provider_rules;
        $candidatesChecked = 0;
        $statementsFound = 0;
        $pendingCandidates = [];
        foreach ($rules as $slug => $rule) {
            $sender = strtolower(trim((string) ($rule['sender_email'] ?? '')));
            if ($sender !== '' && ! filter_var($sender, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $provider = Provider::where('slug', $slug)->first();
            if (! $provider) {
                continue;
            }
            $query = 'has:attachment '.($sender !== '' ? 'from:'.$sender.' ' : '').'subject:{statement settlement transaction report} '.$provider->name.' '.($includeOlder
                ? 'newer_than:365d before:'.now()->subDays(30)->format('Y/m/d')
                : 'newer_than:30d');
            foreach ($this->gmail->search($connection, $query) as $candidate) {
                if ($connection->fresh()?->status !== 'syncing') {
                    return ['candidates_checked' => $candidatesChecked, 'statements_found' => $statementsFound];
                }
                $candidatesChecked++;
                $messageFingerprint = hash_hmac('sha256', $connection->id.'|message|'.$candidate['id'], (string) config('app.key'));
                $seenMessage = GmailStatementMessage::where('gmail_connection_id', $connection->id)
                    ->where('processed_message_fingerprint', $messageFingerprint)->exists();
                $retryablePdf = GmailStatementMessage::where('gmail_connection_id', $connection->id)
                    ->where('processed_message_fingerprint', $messageFingerprint)
                    ->where('file_type', 'pdf')
                    ->where(function ($query): void {
                        $query->where(function ($query): void {
                            $query->where('status', 'unsupported_format')->where('failure_code', 'pdf_not_supported');
                        })->orWhere(function ($query): void {
                            $query->where('status', 'unsupported_schema')->where('failure_code', 'unsupported_schema')->whereNull('headers');
                        });
                    })->exists();
                if ($seenMessage && ! $retryablePdf) {
                    continue;
                }
                try {
                    $metadata = $this->gmail->messageMetadata($connection, $candidate['id']);
                } catch (RuntimeException) {
                    continue;
                }
                $payloadHeaders = (array) data_get($metadata, 'payload.headers', []);
                if (! $this->matchesProviderEvidence($payloadHeaders, $sender, $provider)) {
                    continue;
                }
                $pendingCandidates[] = [
                    'provider' => $provider,
                    'sender' => $sender,
                    'id' => $candidate['id'],
                    'metadata' => $metadata,
                    'received_at' => (int) ($metadata['internalDate'] ?? 0),
                ];
            }
        }

        usort($pendingCandidates, fn (array $first, array $second): int => $second['received_at'] <=> $first['received_at']
            ?: strcmp($first['id'], $second['id']));
        foreach ($pendingCandidates as $candidate) {
            if ($connection->fresh()?->status !== 'syncing') {
                return ['candidates_checked' => $candidatesChecked, 'statements_found' => $statementsFound];
            }
            if ($this->processCandidate($connection, $candidate['provider'], $candidate['sender'], $candidate['id'], $candidate['metadata'])) {
                $statementsFound++;
            }
        }
        if ($connection->fresh()?->status === 'syncing') {
            $connection->update([
                'status' => 'connected',
                'last_synced_at' => now(),
                'last_sync_status' => $statementsFound > 0 ? 'statements_found' : 'no_new_statements',
                'last_error_code' => null,
            ]);
        }

        return ['candidates_checked' => $candidatesChecked, 'statements_found' => $statementsFound];
    }

    private function backfillMessageFingerprints(GmailConnection $connection): void
    {
        $connection->messages()->whereNull('processed_message_fingerprint')->get()->each(function (GmailStatementMessage $message) use ($connection): void {
            if (filled($message->gmail_message_id)) {
                $message->update([
                    'processed_message_fingerprint' => hash_hmac('sha256', $connection->id.'|message|'.$message->gmail_message_id, (string) config('app.key')),
                ]);
            }
        });
    }

    private function processCandidate(GmailConnection $connection, Provider $provider, string $expectedSender, string $messageId, array $messageMetadata): bool
    {
        $messageFingerprint = hash_hmac('sha256', $connection->id.'|message|'.$messageId, (string) config('app.key'));
        if ($connection->fresh()?->status !== 'syncing'
            || ! $this->matchesProviderEvidence((array) data_get($messageMetadata, 'payload.headers', []), $expectedSender, $provider)) {
            return false;
        }
        try {
            $message = $this->gmail->message($connection, $messageId);
        } catch (RuntimeException) {
            return false;
        }
        $payload = (array) ($message['payload'] ?? []);
        $found = false;
        foreach ($this->parts((array) ($payload['parts'] ?? [])) as $part) {
            $attachmentId = data_get($part, 'body.attachmentId');
            $extension = strtolower(pathinfo(basename((string) ($part['filename'] ?? '')), PATHINFO_EXTENSION));
            if (! is_string($attachmentId) || ! in_array($extension, ['csv', 'xlsx', 'xls', 'pdf'], true)) {
                continue;
            }
            $dedupe = hash_hmac('sha256', $connection->id.'|'.$messageId.'|'.$attachmentId, (string) config('app.key'));
            $existing = GmailStatementMessage::where('gmail_connection_id', $connection->id)->where('dedupe_fingerprint', $dedupe)->first();
            $retryUnsupportedPdf = $extension === 'pdf'
                && ($existing?->status === 'unsupported_format' && $existing?->failure_code === 'pdf_not_supported'
                    || $existing?->status === 'unsupported_schema' && $existing?->failure_code === 'unsupported_schema' && $existing?->headers === null);
            if ($existing && $existing->status !== 'failed' && ! $retryUnsupportedPdf) {
                continue;
            }
            $receivedAt = isset($messageMetadata['internalDate']) ? CarbonImmutable::createFromTimestampMs((int) $messageMetadata['internalDate']) : null;
            $record = $existing ?? GmailStatementMessage::create([
                'gmail_connection_id' => $connection->id,
                'agent_profile_id' => $connection->agent_profile_id,
                'provider_id' => $provider->id,
                'dedupe_fingerprint' => $dedupe,
                'processed_message_fingerprint' => $messageFingerprint,
                'gmail_message_id' => $messageId,
                'gmail_attachment_id' => $attachmentId,
                'file_name' => 'statement.'.$extension,
                'file_type' => $extension,
                'received_at' => $receivedAt,
            ]);
            if ($existing) {
                $record->update(['status' => 'discovered', 'failure_code' => null, 'headers' => null]);
            }
            $found = true;
            if (! in_array($extension, ['csv', 'xlsx', 'pdf'], true)) {
                $record->update(['status' => 'unsupported_format', 'failure_code' => 'xls_not_supported']);

                continue;
            }
            try {
                if ($connection->fresh()?->status !== 'syncing') {
                    return false;
                }
                if ((int) data_get($part, 'body.size', 0) > config('gmail_statement.max_attachment_bytes')) {
                    $record->update(['status' => 'rejected', 'failure_code' => 'attachment_too_large']);

                    continue;
                }
                $contents = $this->gmail->attachment($connection, $messageId, $attachmentId);
                if (strlen($contents) > config('gmail_statement.max_attachment_bytes')) {
                    $record->update(['status' => 'rejected', 'failure_code' => 'attachment_too_large']);

                    continue;
                }
                $attachmentFingerprint = hash_hmac('sha256', $connection->id.'|attachment|'.hash('sha256', $contents), (string) config('app.key'));
                $record->update(['attachment_fingerprint' => $attachmentFingerprint]);
                $matchingAttachments = GmailStatementMessage::where('gmail_connection_id', $connection->id)
                    ->where('attachment_fingerprint', $attachmentFingerprint)
                    ->where('id', '!=', $record->id);
                $alreadyHandledAttachment = (clone $matchingAttachments)->whereIn('status', ['processed', 'needs_setup'])->exists();
                $newestAttachment = (clone $matchingAttachments)->orderByDesc('received_at')->orderByDesc('id')->first();
                $isNewestAttachment = ! $newestAttachment
                    || ($record->received_at?->greaterThan($newestAttachment->received_at) ?? false)
                    || ($record->received_at?->equalTo($newestAttachment->received_at) === true && $record->id > $newestAttachment->id);
                if ($alreadyHandledAttachment || ! $isNewestAttachment) {
                    $record->update([
                        'status' => 'duplicate_attachment',
                        'failure_code' => null,
                    ]);

                    continue;
                }
                if ($extension === 'xlsx') {
                    $contents = $this->xlsx->toCsv($contents);
                } elseif ($extension === 'pdf') {
                    $pages = $this->pdfReader->extractPositionedPages($contents);
                    $contents = $this->pdfTables->toCsvFromPositionedPages($pages, $provider->slug)['csv'];
                }
                $schema = $this->imports->inspect($contents);
                $record->update([
                    'headers' => $schema['headers'],
                    'masked_account_identifier' => $this->imports->maskedAccountHint($contents, $schema['headers']),
                    'status' => 'needs_setup',
                ]);
                $this->imports->retainForMapping($record, $contents);
                $profile = $this->matchingProfile($record, $schema['fingerprint'], $schema['headers'], $contents);
                if ($profile) {
                    $this->imports->process($record, $profile);
                }
            } catch (RuntimeException $exception) {
                $failureCode = match ($exception->getMessage()) {
                    'pdf_scanned_unsupported' => 'pdf_scanned_unsupported',
                    'pdf_page_limit_exceeded' => 'pdf_page_limit_exceeded',
                    'pdf_parse_failed' => 'pdf_parse_failed',
                    'unsupported_schema' => $extension === 'pdf' ? 'pdf_table_unrecognized' : 'unsupported_schema',
                    default => 'unsupported_schema',
                };
                $record->update([
                    'status' => in_array($failureCode, ['pdf_scanned_unsupported', 'pdf_page_limit_exceeded', 'pdf_parse_failed'], true) ? 'unsupported_format' : 'unsupported_schema',
                    'failure_code' => $failureCode,
                ]);
            }
        }

        return $found;
    }

    private function matchesProviderEvidence(array $headers, string $expectedSender, Provider $provider): bool
    {
        $from = '';
        $subject = '';
        foreach ($headers as $header) {
            $name = strtolower((string) ($header['name'] ?? ''));
            if ($name === 'from') {
                $from = (string) ($header['value'] ?? '');
            } elseif ($name === 'subject') {
                $subject = (string) ($header['value'] ?? '');
            }
        }
        if ($expectedSender !== '') {
            preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $from, $matches);

            return in_array(strtolower($expectedSender), array_map('strtolower', $matches[0] ?? []), true);
        }

        $providerMentioned = str_contains(strtolower($subject), strtolower($provider->name))
            || str_contains(strtolower($subject), strtolower($provider->slug));
        $statementMentioned = preg_match('/\b(statement|settlement|transaction|report)\b/i', $subject) === 1;

        return $providerMentioned && $statementMentioned;
    }

    private function matchingProfile(GmailStatementMessage $message, string $fingerprint, array $headers, string $contents): ?StatementMappingProfile
    {
        $profiles = StatementMappingProfile::where('agent_profile_id', $message->agent_profile_id)
            ->where('provider_id', $message->provider_id)->where('schema_fingerprint', $fingerprint)->where('status', 'active')->get();
        if ($profiles->isEmpty()) {
            return null;
        }
        $identified = $profiles->filter(function (StatementMappingProfile $profile) use ($contents, $headers): bool {
            $column = $this->imports->identityColumn($profile->column_mapping);
            $index = is_string($column) ? $this->headerIndex($headers, $column) : false;
            if ($index === false) {
                $terminalCount = Terminal::where('agent_profile_id', $profile->agent_profile_id)
                    ->where('provider_id', $profile->provider_id)->where('active', true)->count();

                return $terminalCount === 1 && $profile->match_identifier_fingerprint === null;
            }
            $identifier = $index === false ? '' : $this->singleIdentifier($contents, $index);

            return $identifier !== '' && $profile->match_identifier_fingerprint === hash_hmac('sha256', $identifier, (string) config('app.key'));
        });
        if ($identified->count() === 1) {
            return $identified->first();
        }

        return null;
    }

    private function headerIndex(array $headers, string $column): int|false
    {
        $normalize = fn (string $value): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)) ?? '');
        $expected = $normalize($column);
        foreach ($headers as $index => $header) {
            if ($normalize((string) $header) === $expected) {
                return (int) $index;
            }
        }

        return false;
    }

    private function singleIdentifier(string $contents, int $columnIndex): string
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $contents);
        rewind($handle);
        fgetcsv($handle);
        $identifiers = [];
        while (($row = fgetcsv($handle)) !== false) {
            $identifier = strtolower(trim((string) ($row[$columnIndex] ?? '')));
            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }
            if (count($identifiers) > 1) {
                break;
            }
        }
        fclose($handle);

        return count($identifiers) === 1 ? (string) array_key_first($identifiers) : '';
    }

    private function parts(array $parts): array
    {
        $found = [];
        foreach ($parts as $part) {
            if (is_array($part) && filled($part['filename'] ?? null)) {
                $found[] = $part;
            }
            if (is_array($part) && is_array($part['parts'] ?? null)) {
                $found = [...$found, ...$this->parts($part['parts'])];
            }
        }

        return $found;
    }
}
