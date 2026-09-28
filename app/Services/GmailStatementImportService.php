<?php

namespace App\Services;

use App\Data\NormalizedTransactionData;
use App\Enums\ImportBatchStatus;
use App\Enums\TransactionSource;
use App\Imports\GenericCsvImporter;
use App\Models\GmailStatementMessage;
use App\Models\ImportBatch;
use App\Models\StatementMappingProfile;
use App\Models\Terminal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class GmailStatementImportService
{
    public function __construct(private readonly TransactionIngestionService $ingestion) {}

    /** @return array{headers:list<string>, fingerprint:string} */
    public function inspect(string $contents): array
    {
        $handle = $this->csvHandle($contents);
        $headers = fgetcsv($handle);
        fclose($handle);
        if (! is_array($headers) || count($headers) < 2 || count($headers) > 100) {
            throw new RuntimeException('unsupported_schema');
        }
        $headers = array_map(fn (mixed $header): string => trim((string) $header), $headers);
        if (function_exists('mb_check_encoding') && ! mb_check_encoding(implode(',', $headers), 'UTF-8')) {
            throw new RuntimeException('unsupported_schema');
        }

        return ['headers' => $headers, 'fingerprint' => $this->schemaFingerprint($headers)];
    }

    /**
     * Return a masked identifier only when one header with an explicit account, terminal, merchant, or business name
     * contains exactly one distinct value in the statement.
     *
     * @param  list<string>  $headers
     */
    public function maskedAccountHint(string $contents, array $headers): ?string
    {
        $priority = ['merchantid', 'businessid', 'terminalserial', 'terminalid', 'terminalidentifier', 'provideraccountidentifier', 'accountnumber'];
        $normalizedHeaders = array_map(fn (string $header): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', $header) ?? ''), $headers);
        $columnIndex = false;
        foreach ($priority as $name) {
            $columnIndex = array_search($name, $normalizedHeaders, true);
            if ($columnIndex !== false) {
                break;
            }
        }
        if ($columnIndex === false) {
            return null;
        }

        $handle = $this->csvHandle($contents);
        fgetcsv($handle);
        $identifiers = [];
        while (($row = fgetcsv($handle)) !== false) {
            $identifier = trim((string) ($row[$columnIndex] ?? ''));
            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }
            if (count($identifiers) > 1) {
                fclose($handle);

                return null;
            }
        }
        fclose($handle);
        if (count($identifiers) !== 1) {
            return null;
        }

        $identifier = (string) array_key_first($identifiers);
        $tail = substr(preg_replace('/\W/u', '', $identifier) ?: $identifier, -4);

        return '••••'.($tail !== '' ? $tail : '••••');
    }

    public function retainForMapping(GmailStatementMessage $message, string $contents): void
    {
        $path = 'private/gmail-statements/'.Str::uuid().'.enc';
        Storage::disk('local')->put($path, Crypt::encryptString($contents));
        $message->update(['temporary_file_path' => $path]);
    }

    public function saveMapping(GmailStatementMessage $message, array $mapping, int $terminalId): StatementMappingProfile
    {
        abort_unless($message->temporary_file_path, 422, 'This statement is no longer available. Wait for the next statement email.');
        $contents = Crypt::decryptString(Storage::disk('local')->get($message->temporary_file_path));
        $handle = $this->csvHandle($contents);
        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);
            throw new RuntimeException('unsupported_schema');
        }
        $headers = array_map(fn (mixed $header): string => trim((string) $header), $headers);
        $columnNames = array_filter($mapping, fn (mixed $name): bool => is_string($name) && $name !== '');
        foreach ($columnNames as $name) {
            abort_unless(in_array($name, $headers, true), 422, 'Choose a column from this statement.');
        }
        if (! empty($mapping['provider_account_identifier'])) {
            abort_unless($mapping['provider_account_identifier'] !== ($mapping['external_reference'] ?? null), 422, 'Keep account identifiers separate from transaction references.');
        }
        foreach (['amount', 'transaction_at', 'transaction_status'] as $required) {
            abort_unless(isset($mapping[$required]), 422, 'Map amount, date/time, and status before continuing.');
        }
        $terminal = Terminal::where('agent_profile_id', $message->agent_profile_id)->where('provider_id', $message->provider_id)->where('active', true)->findOrFail($terminalId);

        $accountFingerprint = null;
        $maskedAccount = null;
        $identityColumn = $this->identityColumn($mapping);
        $configuredTerminals = Terminal::where('agent_profile_id', $message->agent_profile_id)->where('provider_id', $message->provider_id)->where('active', true)->count();
        if ($configuredTerminals > 1) {
            abort_unless($this->identityColumn($mapping) !== null, 422, 'Map a merchant, business, terminal, or provider account identifier because this provider has multiple active terminals.');
        }
        if (is_string($identityColumn) && $identityColumn !== '') {
            $columnIndex = array_search($identityColumn, $headers, true);
            $identifiers = [];
            while (($row = fgetcsv($handle)) !== false) {
                $identifier = trim((string) ($row[$columnIndex] ?? ''));
                if ($identifier !== '') {
                    $identifiers[$identifier] = true;
                }
            }
            abort_unless(count($identifiers) === 1, 422, 'This statement must contain exactly one account identifier to save a reusable account match.');
            $identifier = (string) array_key_first($identifiers);
            if ($identityColumn === ($mapping['terminal_identifier'] ?? null) && $terminal->terminal_identifier !== null) {
                abort_unless(hash_equals(hash('sha256', strtolower(trim($terminal->terminal_identifier))), hash('sha256', strtolower($identifier))), 422, 'The statement identifier does not match the selected terminal.');
            }
            $accountFingerprint = hash_hmac('sha256', strtolower($identifier), (string) config('app.key'));
            $maskedAccount = str_repeat('•', 4).substr(preg_replace('/\D/', '', $identifier) ?: $identifier, -4);
        } else {
            $configuredTerminals = Terminal::where('agent_profile_id', $message->agent_profile_id)->where('provider_id', $message->provider_id)->where('active', true)->count();
            abort_unless($configuredTerminals <= 1, 422, 'Map an account or terminal identifier column because this provider has multiple terminals.');
        }
        fclose($handle);

        return StatementMappingProfile::updateOrCreate([
            'agent_profile_id' => $message->agent_profile_id,
            'provider_id' => $message->provider_id,
            'schema_fingerprint' => $this->schemaFingerprint($headers),
            'match_identifier_fingerprint' => $accountFingerprint,
        ], [
            'terminal_id' => $terminal->id,
            'file_type' => $message->file_type,
            'masked_match_identifier' => $maskedAccount,
            'column_mapping' => $mapping,
            'status' => 'active',
        ]);
    }

    public function process(GmailStatementMessage $message, StatementMappingProfile $profile): void
    {
        if (! $message->temporary_file_path || ! $message->provider) {
            $message->update(['status' => 'failed', 'failure_code' => 'attachment_expired']);

            return;
        }
        $terminal = $profile->terminal;
        if (! $terminal || ! $terminal->active || $terminal->agent_profile_id !== $message->agent_profile_id || $terminal->provider_id !== $message->provider_id) {
            $message->update(['status' => 'needs_setup', 'failure_code' => 'terminal_mapping_invalid']);

            return;
        }
        $contents = Crypt::decryptString(Storage::disk('local')->get($message->temporary_file_path));
        $handle = $this->csvHandle($contents);
        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);
            $message->update(['status' => 'unsupported_schema', 'failure_code' => 'invalid_headers']);

            return;
        }
        $headers = array_map(fn (mixed $header): string => trim((string) $header), $headers);
        $importer = new GenericCsvImporter($profile->column_mapping);
        $rows = [];
        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($rowNumber > 5001) {
                fclose($handle);
                $message->update(['status' => 'failed', 'failure_code' => 'row_limit_exceeded']);

                return;
            }
            if (count(array_filter($row, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            if ($this->containsFormula($row)) {
                $rows[] = ['valid' => false, 'normalized_data' => null];

                continue;
            }
            $statusIndex = array_search($profile->column_mapping['transaction_status'] ?? null, $headers, true);
            if ($statusIndex === false || trim((string) ($row[$statusIndex] ?? '')) === '') {
                $rows[] = ['valid' => false, 'normalized_data' => null];

                continue;
            }
            $dateIndex = array_search($profile->column_mapping['transaction_at'] ?? null, $headers, true);
            if ($profile->file_type === 'xlsx' && $dateIndex !== false && is_numeric($row[$dateIndex] ?? null)) {
                $row[$dateIndex] = CarbonImmutable::create(1899, 12, 30)->addSeconds((int) round((float) $row[$dateIndex] * 86400))->toIso8601String();
            }
            $rows[] = $importer->normalize($headers, $row, $rowNumber, $message->provider);
        }
        fclose($handle);

        $batch = DB::transaction(function () use ($message, $profile, $rows): ImportBatch {
            $batch = ImportBatch::create([
                'agent_profile_id' => $message->agent_profile_id,
                'provider_id' => $message->provider_id,
                'filename' => 'gmail-statement.csv',
                'source' => TransactionSource::Statement->value,
                'rows_detected' => count($rows),
                'status' => ImportBatchStatus::Previewed->value,
                'metadata' => ['source' => 'gmail_statement', 'schema_fingerprint' => $profile->schema_fingerprint],
            ]);
            $imported = $duplicates = $failed = 0;
            foreach ($rows as $row) {
                if (! ($row['valid'] ?? false) || ! ($row['normalized_data'] instanceof NormalizedTransactionData)) {
                    $failed++;

                    continue;
                }
                $data = $row['normalized_data'];
                $data = NormalizedTransactionData::fromArray($message->provider, [
                    ...$data->toArray(),
                    'source' => TransactionSource::Statement->value,
                    'terminal_identifier' => $profile->terminal?->terminal_identifier,
                    'provider_fee_supplied' => $data->providerFeeSupplied,
                    'metadata' => ['statement_source' => 'gmail', 'provider_fee_supplied' => $data->providerFeeSupplied],
                ]);
                $result = $this->ingestion->ingest($message->connection->agentProfile, $data, $batch->id);
                $result['status'] === 'duplicate' ? $duplicates++ : $imported++;
            }
            $batch->update(['rows_imported' => $imported, 'rows_duplicate' => $duplicates, 'rows_failed' => $failed, 'status' => ImportBatchStatus::Imported->value, 'imported_at' => now()]);

            return $batch;
        });
        Storage::disk('local')->delete($message->temporary_file_path);
        $message->update([
            'statement_mapping_profile_id' => $profile->id,
            'temporary_file_path' => null,
            'status' => 'processed',
            'failure_code' => null,
            'rows_imported' => $batch->rows_imported,
            'rows_duplicate' => $batch->rows_duplicate,
            'rows_failed' => $batch->rows_failed,
            'processed_at' => now(),
        ]);
        $profile->update(['last_successful_use_at' => now()]);
    }

    public function schemaFingerprint(array $headers): string
    {
        $normalized = array_map(fn (string $header): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($header)) ?? ''), $headers);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $mapping */
    public function identityColumn(array $mapping): ?string
    {
        foreach (['merchant_identifier', 'business_identifier', 'terminal_identifier', 'provider_account_identifier'] as $field) {
            if (is_string($mapping[$field] ?? null) && $mapping[$field] !== '') {
                return $mapping[$field];
            }
        }

        return null;
    }

    private function csvHandle(string $contents)
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $contents);
        rewind($handle);

        return $handle;
    }

    private function containsFormula(array $row): bool
    {
        foreach ($row as $value) {
            if (preg_match('/^\s*[=+@-]\s*[A-Za-z(]/', (string) $value)) {
                return true;
            }
        }

        return false;
    }
}
