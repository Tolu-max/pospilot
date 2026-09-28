<?php

namespace App\Services;

use App\Data\NormalizedTransactionData;
use App\Enums\CustomerChargeSource;
use App\Enums\SettlementStatus;
use App\Enums\TransactionAdjustmentSource;
use App\Enums\TransactionSource;
use App\Models\AgentProfile;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class TransactionIngestionService
{
    public function __construct(private readonly ChargeCalculationService $charges) {}

    /** @return array{status: string, transaction: ?Transaction} */
    public function ingest(AgentProfile $agent, NormalizedTransactionData $data, ?int $importBatchId = null): array
    {
        $fingerprint = $this->fingerprint($data);
        $existing = Transaction::where('agent_profile_id', $agent->id)->where('provider_id', $data->provider->id)->where('import_fingerprint', $fingerprint)->first();
        if ($existing) {
            $existing = DB::transaction(function () use ($existing, $data, $fingerprint): Transaction {
                $existing = Transaction::query()->lockForUpdate()->findOrFail($existing->id);
                $this->enrichProviderFee($existing, $data);
                $this->recordSource($existing, $data, $fingerprint);

                return $existing;
            });

            return ['status' => 'duplicate', 'transaction' => $existing];
        }

        $terminal = $data->terminalIdentifier ? Terminal::firstOrCreate(['agent_profile_id' => $agent->id, 'provider_id' => $data->provider->id, 'terminal_identifier' => $data->terminalIdentifier], ['name' => 'Imported terminal '.$data->terminalIdentifier]) : null;
        $calculatedCharge = $this->charges->calculate($agent, $data->amount, $data->provider->id);
        $customerChargeComponents = collect($data->adjustments)->filter(fn ($component): bool => $component->type->value === 'customer_charge');
        $componentCustomerCharge = $customerChargeComponents->isNotEmpty()
            ? $customerChargeComponents->reduce(fn (string $total, $component): string => Money::add($total, $component->amount), '0.00')
            : null;
        $componentChargeSource = $customerChargeComponents->first()?->source;

        if ($componentChargeSource === TransactionAdjustmentSource::Calculated && $componentCustomerCharge !== null) {
            $calculatedCharge = $componentCustomerCharge;
        }

        if ($data->customerCharge !== null && $componentCustomerCharge !== null && Money::compare($data->customerCharge, $componentCustomerCharge) !== 0) {
            throw new \InvalidArgumentException('Normalized customer charge does not match its component breakdown.');
        }

        $providedCustomerCharge = $data->customerCharge ?? $componentCustomerCharge;
        $defaultChargeSource = match ($componentChargeSource) {
            TransactionAdjustmentSource::Provider => CustomerChargeSource::Imported->value,
            TransactionAdjustmentSource::Manual => CustomerChargeSource::Manual->value,
            TransactionAdjustmentSource::Calculated => CustomerChargeSource::Calculated->value,
            default => $providedCustomerCharge === null ? CustomerChargeSource::Calculated->value : CustomerChargeSource::Imported->value,
        };
        $customerChargeSource = $data->customerChargeSource ?? $defaultChargeSource;
        $effectiveCharge = $providedCustomerCharge ?? $calculatedCharge;
        $importedCustomerCharge = $customerChargeSource === CustomerChargeSource::Imported || $customerChargeSource === CustomerChargeSource::Imported->value
            ? $providedCustomerCharge
            : null;
        $attributes = ['agent_profile_id' => $agent->id, 'provider_id' => $data->provider->id, 'terminal_id' => $terminal?->id, 'import_batch_id' => $importBatchId, 'external_reference' => $data->externalReference, 'transaction_type' => $data->transactionType, 'amount' => $data->amount, 'customer_charge' => $effectiveCharge, 'imported_customer_charge' => $importedCustomerCharge, 'calculated_customer_charge' => $calculatedCharge, 'customer_charge_override' => null, 'customer_charge_source' => $customerChargeSource instanceof CustomerChargeSource ? $customerChargeSource->value : $customerChargeSource, 'provider_fee' => $data->providerFee, 'provider_fee_supplied' => $data->providerFeeSupplied, 'transaction_status' => $data->status->value, 'settlement_status' => ($data->settlementStatus ?? SettlementStatus::Pending)->value, 'transaction_at' => $data->transactionAt, 'settled_at' => $data->settledAt, 'source' => $data->source->value, 'import_fingerprint' => $fingerprint, 'metadata' => [...$data->metadata, 'provider_fee_supplied' => $data->providerFeeSupplied, ...($data->providerFeeSupplied ? ['provider_fee_provenance' => $data->source->provenance()] : [])]];
        try {
            $transaction = DB::transaction(function () use ($attributes, $data, $fingerprint): Transaction {
                $transaction = Transaction::create($attributes);
                $transaction->provider_fee_supplied = $data->providerFeeSupplied;
                $transaction->provider_fee_components_complete = $data->providerFeeComponentsComplete;
                $transaction->save();

                foreach ($data->adjustments as $adjustment) {
                    $transaction->adjustments()->create($adjustment->toArray());
                }

                $this->recordSource($transaction, $data, $fingerprint);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) {
            $transaction = Transaction::where('agent_profile_id', $agent->id)->where('provider_id', $data->provider->id)->where('import_fingerprint', $fingerprint)->first();

            if ($transaction !== null) {
                $this->enrichProviderFee($transaction, $data);
                $this->recordSource($transaction, $data, $fingerprint);
            }

            return ['status' => 'duplicate', 'transaction' => $transaction];
        }

        return ['status' => 'imported', 'transaction' => $transaction];
    }

    public function isDuplicate(AgentProfile $agent, NormalizedTransactionData $data): bool
    {
        return Transaction::where('agent_profile_id', $agent->id)->where('provider_id', $data->provider->id)->where('import_fingerprint', $this->fingerprint($data))->exists();
    }

    public function fingerprint(NormalizedTransactionData $data): string
    {
        $reference = trim((string) $data->externalReference);
        $businessIdentifier = strtolower(trim((string) ($data->metadata['business_id'] ?? '')));
        $merchantIdentifier = strtolower(trim((string) ($data->metadata['merchant_id'] ?? '')));
        $accountFingerprint = strtolower(trim((string) ($data->metadata['provider_account_identifier_fingerprint'] ?? '')));
        $terminalIdentifier = strtolower(trim((string) $data->terminalIdentifier));
        $strongReference = $this->strongReference($data, $reference);

        if ($strongReference !== null) {
            return hash('sha256', implode('|', [$data->provider->id, 'ref', $strongReference['type'], $strongReference['value']]));
        }

        $hasTerminalScope = $terminalIdentifier !== '';
        $identity = $reference !== ''
            ? implode('|', [$data->provider->id, 'ref', 'provider_reference', $reference])
            : implode('|', [
                $data->provider->id,
                'fallback',
                $businessIdentifier,
                $merchantIdentifier,
                $accountFingerprint,
                $terminalIdentifier,
                strtolower(trim($data->transactionType)),
                $data->amount,
                $data->transactionAt->toIso8601String(),
                ...(! $hasTerminalScope ? [$data->source->value, $this->sourceReference($data)] : []),
            ]);

        return hash('sha256', $identity);
    }

    /** @return array{type: string, value: string}|null */
    private function strongReference(NormalizedTransactionData $data, string $externalReference): ?array
    {
        $references = [
            'provider_transaction_id' => $data->metadata['provider_transaction_id'] ?? null,
            'rrn' => $data->metadata['rrn'] ?? $data->metadata['retrieval_reference_number'] ?? null,
            'provider_order_reference' => $data->metadata['provider_order_reference'] ?? $data->metadata['pay_no'] ?? $data->metadata['order_no'] ?? null,
            'provider_reference' => $externalReference,
        ];

        foreach ($references as $type => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return ['type' => $type, 'value' => trim((string) $value)];
            }
        }

        return null;
    }

    private function enrichProviderFee(Transaction $transaction, NormalizedTransactionData $data): void
    {
        if (! $data->providerFeeSupplied || ! in_array($data->source, [TransactionSource::Statement, TransactionSource::Csv, TransactionSource::Api, TransactionSource::Webhook], true)) {
            return;
        }

        $metadata = $transaction->metadata ?? [];
        $newProvenance = $data->source->provenance();
        $existingProvenance = $metadata['provider_fee_provenance']
            ?? ($transaction->source instanceof TransactionSource ? $transaction->source->provenance() : TransactionSource::Manual->provenance());
        $sourcePriority = (array) config('pospilot.ingestion_source_priority', []);
        $canSetMissingFee = ! $transaction->provider_fee_supplied;
        $canReplaceLessAuthoritativeFee = $transaction->provider_fee_supplied
            && ($sourcePriority[$newProvenance] ?? 0) > ($sourcePriority[$existingProvenance] ?? 0);

        if (! $canSetMissingFee && ! $canReplaceLessAuthoritativeFee) {
            return;
        }

        if ($transaction->provider_fee_supplied) {
            $metadata['provider_fee_history'] = [...($metadata['provider_fee_history'] ?? []), [
                'amount' => (string) $transaction->provider_fee,
                'source' => $existingProvenance,
                'replaced_at' => now()->toIso8601String(),
            ]];
        }

        $metadata['provider_fee_supplied'] = true;
        $metadata['provider_fee_provenance'] = $newProvenance;
        $transaction->provider_fee = $data->providerFee;
        $transaction->provider_fee_supplied = true;
        $transaction->provider_fee_components_complete = $data->providerFeeComponentsComplete || $transaction->provider_fee_components_complete;
        $transaction->metadata = $metadata;
        $transaction->save();
    }

    private function recordSource(Transaction $transaction, NormalizedTransactionData $data, string $transactionFingerprint): void
    {
        $safeMetadata = array_intersect_key($data->metadata, array_flip([
            'provider_transaction_id',
            'provider_order_reference',
            'pay_no',
            'order_no',
            'rrn',
            'retrieval_reference_number',
            'business_id',
            'merchant_id',
            'provider_account_identifier_fingerprint',
            'settlement_reference',
        ]));
        $sourceReferenceFingerprint = hash('sha256', $this->sourceReference($data) ?: $transactionFingerprint);
        $metadataFingerprint = hash('sha256', json_encode($safeMetadata, JSON_THROW_ON_ERROR));
        $now = now();

        DB::table('transaction_source_records')->upsert([[
            'transaction_id' => $transaction->id,
            'source_type' => $data->source->provenance(),
            'source_reference_fingerprint' => $sourceReferenceFingerprint,
            'metadata_fingerprint' => $metadataFingerprint,
            'observed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['transaction_id', 'source_type', 'source_reference_fingerprint'], ['metadata_fingerprint', 'updated_at']);
    }

    private function sourceReference(NormalizedTransactionData $data): string
    {
        foreach (['source_reference', 'statement_attachment_fingerprint', 'statement_fingerprint', 'provider_event_id'] as $key) {
            $value = $data->metadata[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return trim((string) $data->externalReference);
    }
}
