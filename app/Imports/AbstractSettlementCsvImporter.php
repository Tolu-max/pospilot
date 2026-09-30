<?php

namespace App\Imports;

use App\Data\NormalizedSettlementData;
use App\Models\Provider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

abstract class AbstractSettlementCsvImporter implements SettlementCsvImporter
{
    abstract protected function aliases(): array;

    public function resolveHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $index => $header) {
            $normalized[$this->headerKey($header)] = $index;
        }
        $resolved = [];
        foreach ($this->aliases() as $field => $options) {
            foreach ($options as $option) {
                if (array_key_exists($this->headerKey($option), $normalized)) {
                    $resolved[$field] = $normalized[$this->headerKey($option)];
                    break;
                }
            }
        }

        return $resolved;
    }

    public function normalize(array $headers, array $row, int $rowNumber, ?Provider $provider = null): array
    {
        $indexes = $this->resolveHeaders($headers);
        $errors = [];
        foreach (['settlement_date'] as $required) {
            if (! array_key_exists($required, $indexes)) {
                $errors[] = "Missing required column: {$required}";
            }
        }
        $value = fn (string $key): ?string => array_key_exists($key, $indexes) ? trim((string) ($row[$indexes[$key]] ?? '')) : null;
        $date = $value('settlement_date');
        try {
            $date = $date ? CarbonImmutable::parse($date)->toIso8601String() : null;
        } catch (\Throwable) {
            $errors[] = 'Invalid settlement date';
            $date = null;
        }
        $expected = $this->decimal($value('expected_amount'), 'expected amount', $errors, true);
        $actual = $this->decimal($value('actual_amount'), 'actual amount', $errors, true);
        $gross = $this->decimal($value('gross_transaction_amount'), 'gross transaction amount', $errors, true);
        $fee = $this->decimal($value('provider_fee'), 'provider fee', $errors, true);
        if ($expected === null && $actual === null) {
            $errors[] = 'Expected or actual settlement amount is required';
        }
        $status = $this->status($value('status'));
        if (! $status) {
            $errors[] = 'Invalid settlement status';
            $status = 'pending';
        }
        $normalized = ['external_reference' => $value('external_reference') ?: null, 'terminal_identifier' => $value('terminal_identifier') ?: null, 'settlement_date' => $date, 'gross_transaction_amount' => $gross, 'provider_fee' => $fee, 'expected_amount' => $expected, 'actual_amount' => $actual, 'status' => $status, 'source' => 'csv'];

        return ['row_number' => $rowNumber, 'valid' => count($errors) === 0, 'errors' => $errors, 'normalized' => $normalized, 'normalized_data' => $provider && count($errors) === 0 ? NormalizedSettlementData::fromArray($provider, $normalized) : null];
    }

    protected function status(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return 'pending';
        }

        return match (strtolower(trim($status))) {
            'settled','paid','complete','completed','success','successful' => 'settled', 'pending','processing' => 'pending', 'unreconciled','unmatched' => 'unreconciled', 'disputed' => 'disputed', default => null
        };
    }

    protected function headerKey(string $header): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', preg_replace('/^\xEF\xBB\xBF/', '', trim($header))) ?? '');
    }

    private function decimal(?string $value, string $label, array &$errors, bool $optional = false): ?string
    {
        if ($value === null || $value === '') {
            if (! $optional) {
                $errors[] = ucfirst($label).' is required';
            }

            return null;
        } $clean = str_replace([',', '₦', 'NGN', 'ngn', ' '], '', $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            $errors[] = "Invalid {$label}";

            return null;
        } try {
            return BigDecimal::of($clean)->toScale(2, RoundingMode::HalfUp)->__toString();
        } catch (\Throwable) {
            $errors[] = "Invalid {$label}";

            return null;
        }
    }
}
