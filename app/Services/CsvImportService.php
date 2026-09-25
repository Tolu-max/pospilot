<?php

namespace App\Services;

use App\Data\NormalizedTransactionData;
use App\Enums\ImportBatchStatus;
use App\Enums\TransactionSource;
use App\Imports\CsvImporterFactory;
use App\Models\AgentProfile;
use App\Models\ImportBatch;
use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CsvImportService
{
    private readonly TransactionIngestionService $ingestion;

    public function __construct(private readonly CsvImporterFactory $factory, ChargeCalculationService $charges, ?TransactionIngestionService $ingestion = null)
    {
        $this->ingestion = $ingestion ?? new TransactionIngestionService($charges);
    }

    public function preview(UploadedFile $file, AgentProfile $agent, Provider $provider, array $mapping = []): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $headers = fgetcsv($handle);
        if (! is_array($headers) || count($headers) < 2) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \InvalidArgumentException('The CSV must include a header row and at least one data column.');
        }
        $headers = array_map(fn ($header) => trim((string) $header), $headers);
        if (function_exists('mb_check_encoding') && ! mb_check_encoding(implode(',', $headers), 'UTF-8')) {
            fclose($handle);
            throw new \InvalidArgumentException('The CSV must use UTF-8 encoding.');
        }
        $importer = $this->factory->make($provider, $mapping);
        $rows = [];
        $seen = [];
        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            if ($rowNumber > 5001) {
                fclose($handle);
                throw new \InvalidArgumentException('The CSV cannot contain more than 5,000 data rows.');
            }
            if ($this->containsFormula($row)) {
                $rows[] = ['row_number' => $rowNumber, 'valid' => false, 'errors' => ['Spreadsheet formulas are not accepted.'], 'normalized' => [], 'duplicate' => false];

                continue;
            }
            $result = $importer->normalize($headers, $row, $rowNumber, $provider);
            $data = $result['normalized_data'];
            unset($result['normalized_data']);
            if ($result['valid'] && $data instanceof NormalizedTransactionData) {
                $result['normalized']['fingerprint'] = $this->ingestion->fingerprint($data);
                $result['duplicate'] = isset($seen[$result['normalized']['fingerprint']]) || $this->ingestion->isDuplicate($agent, $data);
                $seen[$result['normalized']['fingerprint']] = true;
            } else {
                $result['duplicate'] = false;
            }
            $rows[] = $result;
        }
        fclose($handle);
        if ($rows === []) {
            throw new \InvalidArgumentException('The CSV contains no transaction rows.');
        }
        $valid = collect($rows)->where('valid', true);

        return ['headers' => $headers, 'rows' => $rows, 'rows_detected' => count($rows), 'valid' => $valid->where('duplicate', false)->count(), 'duplicates' => $valid->where('duplicate', true)->count(), 'invalid' => collect($rows)->where('valid', false)->count()];
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

    public function importPreview(AgentProfile $agent, Provider $provider, string $filename, array $preview): array
    {
        return DB::transaction(function () use ($agent, $provider, $filename, $preview) {
            $batch = ImportBatch::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'filename' => Str::limit(basename($filename), 255, ''), 'source' => TransactionSource::Csv->value, 'rows_detected' => $preview['rows_detected'], 'status' => ImportBatchStatus::Previewed->value, 'metadata' => ['headers' => $preview['headers'] ?? []]]);
            $imported = $duplicates = $failed = 0;
            foreach ($preview['rows'] as $row) {
                if (! $row['valid']) {
                    $failed++;

                    continue;
                }
                $data = NormalizedTransactionData::fromArray($provider, [...$row['normalized'], 'source' => TransactionSource::Csv->value]);
                $result = $this->ingestion->ingest($agent, $data, $batch->id);
                if ($result['status'] === 'duplicate') {
                    $duplicates++;
                } else {
                    $imported++;
                }
            }
            $batch->update(['rows_imported' => $imported, 'rows_duplicate' => $duplicates, 'rows_failed' => $failed, 'status' => ImportBatchStatus::Imported->value, 'imported_at' => now()]);
            $agent->providerConnections()->where('provider_id', $provider->id)->where('connection_type', 'csv')->update(['last_synced_at' => now(), 'last_sync_status' => 'imported', 'last_sync_error' => null]);

            return ['batch_id' => $batch->id, 'rows_detected' => $batch->rows_detected, 'rows_imported' => $imported, 'rows_duplicate' => $duplicates, 'rows_failed' => $failed];
        });
    }
}
