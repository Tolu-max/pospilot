<?php

namespace App\Imports;

use App\Data\NormalizedTransactionData;
use App\Models\Provider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

abstract class AbstractTransactionCsvImporter implements TransactionCsvImporter
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
                $key = $this->headerKey($option);
                if (array_key_exists($key, $normalized)) {
                    $resolved[$field] = $normalized[$key];
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
        foreach (['amount', 'transaction_at'] as $required) {
            if (! array_key_exists($required, $indexes)) {
                $errors[] = "Missing required column: {$required}";
            }
        }
        $value = fn (string $key): ?string => array_key_exists($key, $indexes) ? trim((string) ($row[$indexes[$key]] ?? '')) : null;
        $amount = $this->decimal($value('amount'), 'amount', $errors);
        $providerFee = $this->decimal($value('provider_fee'), 'provider fee', $errors, true) ?? '0.00';
        $customerCharge = $this->decimal($value('customer_charge'), 'customer charge', $errors, true);
        if ($amount !== null && BigDecimal::of($amount)->compareTo(0) <= 0) {
            $errors[] = 'Transaction amount must be greater than zero.';
        }
        if ($customerCharge !== null && BigDecimal::of($customerCharge)->compareTo(0) < 0) {
            $errors[] = 'Customer charge cannot be negative.';
        }
        $date = $value('transaction_at');
        try {
            $date = $date ? CarbonImmutable::parse($date)->toIso8601String() : null;
        } catch (\Throwable) {
            $errors[] = 'Invalid transaction date/time';
            $date = null;
        }
        if (! $date && ! in_array('Missing required column: transaction_at', $errors, true)) {
            $errors[] = 'Transaction date/time is required';
        }
        $status = $this->status($value('transaction_status'));
        if (! $status) {
            $errors[] = 'Invalid transaction status';
            $status = 'successful';
        }
        $normalized = ['external_reference' => $value('external_reference') ?: null, 'amount' => $amount, 'customer_charge' => $customerCharge, 'provider_fee' => $providerFee, 'provider_fee_supplied' => $value('provider_fee') !== null && $value('provider_fee') !== '', 'transaction_status' => $status, 'transaction_at' => $date, 'terminal_identifier' => $value('terminal_identifier') ?: null, 'transaction_type' => strtolower($value('transaction_type') ?: 'transfer'), 'source' => 'csv'];

        return ['row_number' => $rowNumber, 'valid' => count($errors) === 0, 'errors' => $errors, 'normalized' => $normalized, 'normalized_data' => $provider && count($errors) === 0 ? NormalizedTransactionData::fromArray($provider, $normalized) : null];
    }

    protected function status(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return 'successful';
        }

        return match (strtolower(trim($status))) {
            'success','successful','completed','complete','paid','approved' => 'successful', 'pending','processing' => 'pending', 'failed','failure','error','declined' => 'failed', 'reversed','reversal','refunded','refunded/reversed' => 'reversed', default => null
        };
    }

    protected function headerKey(string $header): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', preg_replace('/^\\xEF\\xBB\\xBF/', '', trim($header))) ?? '');
    }

    private function decimal(?string $value, string $label, array &$errors, bool $optional = false): ?string
    {
        if ($value === null || $value === '') {
            if (! $optional) {
                $errors[] = ucfirst($label).' is required';
            }

            return null;
        }
        $clean = str_replace([',', '₦', 'NGN', 'ngn', ' '], '', $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            $errors[] = "Invalid {$label}";

            return null;
        }
        try {
            return BigDecimal::of($clean)->toScale(2, RoundingMode::HalfUp)->__toString();
        } catch (\Throwable) {
            $errors[] = "Invalid {$label}";

            return null;
        }
    }
}
