<?php

namespace App\Services;

use App\Enums\CustomerChargeSource;
use App\Enums\FinancialDataStatus;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Support\Collection;

final class FinancialDataStatusService
{
    /** @return array{financial_data_status:string,earnings_status:string,is_final:bool,reasons:list<string>,customer_charge_source:?string,provider_fee_supplied:bool,provider_fee_components_complete:bool,is_calculated:bool,is_manually_overridden:bool} */
    public function forTransaction(Transaction $transaction): array
    {
        $metadata = $transaction->metadata ?? [];
        $providerFeeSupplied = (bool) ($metadata['provider_fee_supplied'] ?? $transaction->provider_fee_supplied ?? true);
        $providerFeeComponentsComplete = (bool) ($transaction->provider_fee_components_complete ?? false);
        $reasons = [];

        if ($transaction->transaction_status === TransactionStatus::Successful) {
            if (! $providerFeeSupplied && ! $providerFeeComponentsComplete) {
                $reasons[] = 'provider_fee_missing';
            } elseif (! $providerFeeComponentsComplete) {
                $reasons[] = 'provider_fee_components_incomplete';
            }
        }
        $chargeSource = $transaction->customer_charge_source?->value;

        $status = match (true) {
            $reasons !== [] => FinancialDataStatus::Provisional,
            $transaction->customer_charge_override !== null || $chargeSource === CustomerChargeSource::Manual->value => FinancialDataStatus::ManuallyOverridden,
            $chargeSource === CustomerChargeSource::Calculated->value => FinancialDataStatus::Calculated,
            default => FinancialDataStatus::CompleteVerified,
        };
        $isManuallyOverridden = $transaction->customer_charge_override !== null || $chargeSource === CustomerChargeSource::Manual->value;
        $isCalculated = $chargeSource === CustomerChargeSource::Calculated->value;

        return [
            'financial_data_status' => $status->value,
            'earnings_status' => $reasons === [] ? 'final' : 'provisional',
            'is_final' => $reasons === [],
            'reasons' => $reasons,
            'customer_charge_source' => $chargeSource,
            'provider_fee_supplied' => $providerFeeSupplied,
            'provider_fee_components_complete' => $providerFeeComponentsComplete,
            'is_calculated' => $isCalculated,
            'is_manually_overridden' => $isManuallyOverridden,
        ];
    }

    /** @param Collection<int, Transaction> $transactions
     * @return array{financial_data_status:string,earnings_status:string,is_final:bool,provisional_transaction_count:int,provisional_reasons:list<array{code:string,transaction_count:int}>,calculated_transaction_count:int,manually_overridden_transaction_count:int,complete_verified_transaction_count:int}
     */
    public function summarize(Collection $transactions): array
    {
        $statuses = $transactions->map(fn (Transaction $transaction): array => $this->forTransaction($transaction));
        $provisional = $statuses->where('earnings_status', 'provisional');
        $reasons = $provisional->flatMap(fn (array $status): array => $status['reasons'])
            ->countBy()
            ->map(fn (int $count, string $code): array => ['code' => $code, 'transaction_count' => $count])
            ->values()
            ->all();
        $financialDataStatus = match (true) {
            $provisional->isNotEmpty() => FinancialDataStatus::Provisional,
            $statuses->contains(fn (array $status): bool => $status['financial_data_status'] === FinancialDataStatus::ManuallyOverridden->value) => FinancialDataStatus::ManuallyOverridden,
            $statuses->contains(fn (array $status): bool => $status['financial_data_status'] === FinancialDataStatus::Calculated->value) => FinancialDataStatus::Calculated,
            default => FinancialDataStatus::CompleteVerified,
        };

        return [
            'financial_data_status' => $financialDataStatus->value,
            'earnings_status' => $provisional->isEmpty() ? 'final' : 'provisional',
            'is_final' => $provisional->isEmpty(),
            'provisional_transaction_count' => $provisional->count(),
            'provisional_reasons' => $reasons,
            'calculated_transaction_count' => $statuses->where('is_calculated', true)->count(),
            'manually_overridden_transaction_count' => $statuses->where('is_manually_overridden', true)->count(),
            'complete_verified_transaction_count' => $statuses->where('financial_data_status', FinancialDataStatus::CompleteVerified->value)->count(),
        ];
    }
}
