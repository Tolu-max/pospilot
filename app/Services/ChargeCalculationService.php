<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Models\AgentProfile;
use App\Models\ChargeRule;
use App\Models\Transaction;
use App\Support\Money;

final class ChargeCalculationService
{
    public function ruleFor(AgentProfile $agent, string|int|float $amount, ?int $providerId = null): ?ChargeRule
    {
        return $agent->chargeRules()->where('active', true)->get()->filter(function (ChargeRule $rule) use ($amount, $providerId) {
            $providerMatches = $rule->provider_id === null || $rule->provider_id === $providerId;
            $aboveMinimum = Money::compare($amount, $rule->minimum_amount) >= 0;
            $belowMaximum = $rule->maximum_amount === null || Money::compare($amount, $rule->maximum_amount) <= 0;

            return $providerMatches && $aboveMinimum && $belowMaximum;
        })->sort(function (ChargeRule $a, ChargeRule $b) {
            $providerScore = ($b->provider_id !== null) <=> ($a->provider_id !== null);

            return $providerScore !== 0 ? $providerScore : (($b->priority <=> $a->priority) ?: (Money::compare($a->minimum_amount, $b->minimum_amount) * -1));
        })->first();
    }

    public function calculate(AgentProfile $agent, string|int|float $amount, ?int $providerId = null): string
    {
        $rule = $this->ruleFor($agent, $amount, $providerId);
        if (! $rule) {
            return '0.00';
        }

        return $rule->charge_type === ChargeType::Percentage ? Money::percentage($amount, $rule->charge_value) : Money::multiply($rule->charge_value, 1);
    }

    public function applyTo(Transaction $transaction): string
    {
        $charge = $transaction->customer_charge_override ?? $this->calculate($transaction->agentProfile, $transaction->amount, $transaction->provider_id);
        $transaction->customer_charge = $charge;

        return $charge;
    }
}
