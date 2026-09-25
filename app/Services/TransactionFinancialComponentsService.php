<?php

namespace App\Services;

use App\Enums\TransactionAdjustmentDirection;
use App\Enums\TransactionAdjustmentSource;
use App\Enums\TransactionAdjustmentType;
use App\Models\Transaction;
use App\Models\TransactionAdjustment;
use App\Support\Money;

final class TransactionFinancialComponentsService
{
    /** @return array{customer_charges:string,provider_fees:string,other_credits:string,net_provider_deduction:string} */
    public function summarize(Transaction $transaction): array
    {
        $adjustments = $transaction->relationLoaded('adjustments')
            ? $transaction->adjustments
            : $transaction->adjustments()->get();
        $customerChargeComponents = $adjustments
            ->filter(fn ($adjustment): bool => $adjustment->type === TransactionAdjustmentType::CustomerCharge
                && $adjustment->direction === TransactionAdjustmentDirection::Credit
                && $adjustment->source !== TransactionAdjustmentSource::Manual);
        $customerCharges = $transaction->customer_charge_override !== null
            ? (string) $transaction->customer_charge_override
            : ($customerChargeComponents->isNotEmpty()
                ? $this->sum($customerChargeComponents->all())
                : (string) ($transaction->customer_charge ?? '0.00'));
        $debitComponents = $adjustments
            ->filter(fn ($adjustment): bool => $adjustment->direction === TransactionAdjustmentDirection::Debit
                && $adjustment->type !== TransactionAdjustmentType::CustomerCharge);
        $otherCreditComponents = $adjustments
            ->filter(fn ($adjustment): bool => $adjustment->direction === TransactionAdjustmentDirection::Credit
                && $adjustment->type !== TransactionAdjustmentType::CustomerCharge);

        if ($transaction->provider_fee_components_complete) {
            $providerFees = $this->sum($debitComponents->all());
        } else {
            $providerFees = (string) ($transaction->provider_fee ?? '0.00');
            $additiveComponents = $debitComponents->reject(fn ($adjustment): bool => $adjustment->type === TransactionAdjustmentType::ProviderFee
                || $adjustment->source === TransactionAdjustmentSource::Provider);
            $providerFees = Money::add($providerFees, $this->sum($additiveComponents->all()));
        }

        $otherCredits = $this->sum($otherCreditComponents->all());

        return [
            'customer_charges' => $customerCharges,
            'provider_fees' => $providerFees,
            'other_credits' => $otherCredits,
            'net_provider_deduction' => Money::subtract($providerFees, $otherCredits),
        ];
    }

    /** @param list<TransactionAdjustment> $components */
    private function sum(array $components): string
    {
        return collect($components)->reduce(fn (string $total, $component): string => Money::add($total, $component->amount), '0.00');
    }
}
