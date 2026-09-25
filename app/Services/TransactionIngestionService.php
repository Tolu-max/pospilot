<?php

namespace App\Services;

use App\Data\NormalizedTransactionData;
use App\Enums\CustomerChargeSource;
use App\Enums\SettlementStatus;
use App\Enums\TransactionAdjustmentSource;
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
        $attributes = ['agent_profile_id' => $agent->id, 'provider_id' => $data->provider->id, 'terminal_id' => $terminal?->id, 'import_batch_id' => $importBatchId, 'external_reference' => $data->externalReference, 'transaction_type' => $data->transactionType, 'amount' => $data->amount, 'customer_charge' => $effectiveCharge, 'imported_customer_charge' => $importedCustomerCharge, 'calculated_customer_charge' => $calculatedCharge, 'customer_charge_override' => null, 'customer_charge_source' => $customerChargeSource instanceof CustomerChargeSource ? $customerChargeSource->value : $customerChargeSource, 'provider_fee' => $data->providerFee, 'provider_fee_supplied' => $data->providerFeeSupplied, 'transaction_status' => $data->status->value, 'settlement_status' => ($data->settlementStatus ?? SettlementStatus::Pending)->value, 'transaction_at' => $data->transactionAt, 'settled_at' => $data->settledAt, 'source' => $data->source->value, 'import_fingerprint' => $fingerprint, 'metadata' => [...$data->metadata, 'provider_fee_supplied' => $data->providerFeeSupplied]];
        try {
            $transaction = DB::transaction(function () use ($attributes, $data): Transaction {
                $transaction = Transaction::create($attributes);
                $transaction->provider_fee_supplied = $data->providerFeeSupplied;
                $transaction->provider_fee_components_complete = $data->providerFeeComponentsComplete;
                $transaction->save();

                foreach ($data->adjustments as $adjustment) {
                    $transaction->adjustments()->create($adjustment->toArray());
                }

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) {
            $transaction = Transaction::where('agent_profile_id', $agent->id)->where('provider_id', $data->provider->id)->where('import_fingerprint', $fingerprint)->first();

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
        $reference = strtolower(trim((string) $data->externalReference));
        $identity = $reference !== '' ? implode('|', [$data->provider->id, 'ref', $reference]) : implode('|', [$data->provider->id, 'fallback', $data->amount, $data->providerFee, $data->transactionAt->toIso8601String(), strtolower(trim((string) $data->terminalIdentifier))]);

        return hash('sha256', $identity);
    }
}
