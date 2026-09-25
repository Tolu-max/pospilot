<?php

namespace App\Services;

use App\Enums\ReconciliationIssueType;
use App\Enums\ReconciliationOutcome;
use App\Enums\SettlementStatus;
use App\Enums\TransactionStatus;
use App\Models\AgentProfile;
use App\Models\Settlement;
use App\Support\Money;
use Carbon\CarbonImmutable;

final class ReconciliationService
{
    public function __construct(private readonly TransactionFinancialComponentsService $components = new TransactionFinancialComponentsService) {}

    public function expectedSettlementFor(AgentProfile $agent, int $providerId, ?int $terminalId = null, ?string $date = null): string
    {
        $query = $agent->transactions()->where('provider_id', $providerId)->where('transaction_status', TransactionStatus::Successful->value);
        if ($terminalId) {
            $query->where('terminal_id', $terminalId);
        }
        if ($date) {
            $query->whereDate('transaction_at', $date);
        }

        return $query->with('adjustments')->get()->reduce(function (string $total, $transaction): string {
            $financials = $this->components->summarize($transaction);

            return Money::add($total, Money::subtract($transaction->amount, $financials['net_provider_deduction']));
        }, '0.00');
    }

    public function compare(Settlement $settlement): array
    {
        $expected = $settlement->expected_amount !== null ? (string) $settlement->expected_amount : $this->expectedSettlementFor($settlement->agentProfile, $settlement->provider_id, $settlement->terminal_id, $settlement->settlement_date?->toDateString());
        $actual = $settlement->actual_amount === null ? null : (string) $settlement->actual_amount;
        $transactions = $settlement->agentProfile->transactions()->where('provider_id', $settlement->provider_id)->where('transaction_status', TransactionStatus::Successful->value)->whereDate('transaction_at', $settlement->settlement_date)->when($settlement->terminal_id, fn ($q) => $q->where('terminal_id', $settlement->terminal_id))->get();
        $pending = $settlement->agentProfile->transactions()->where('provider_id', $settlement->provider_id)->where('transaction_status', TransactionStatus::Pending->value)->whereDate('transaction_at', $settlement->settlement_date)->when($settlement->terminal_id, fn ($q) => $q->where('terminal_id', $settlement->terminal_id))->count();
        $reversed = $settlement->agentProfile->transactions()->where('provider_id', $settlement->provider_id)->where('transaction_status', TransactionStatus::Reversed->value)->whereDate('transaction_at', $settlement->settlement_date)->when($settlement->terminal_id, fn ($q) => $q->where('terminal_id', $settlement->terminal_id))->count();
        $discrepancy = $actual === null ? null : Money::subtract($actual, $expected);
        $issues = [];
        if ($actual === null) {
            $issues[] = ['type' => ReconciliationIssueType::MissingSettlement->value, 'message' => 'Settlement has not been received.'];
        }
        if ($actual !== null && Money::compare($actual, $expected) !== 0) {
            $issues[] = ['type' => ReconciliationIssueType::SettlementAmountMismatch->value, 'message' => 'Actual settlement differs from expected settlement.'];
        }
        if ($pending > 0) {
            $issues[] = ['type' => ReconciliationIssueType::TransactionPending->value, 'message' => "{$pending} transaction(s) are still pending."];
        }
        if ($reversed > 0) {
            $issues[] = ['type' => ReconciliationIssueType::ReversedTransaction->value, 'message' => "{$reversed} reversed transaction(s) were found in the settlement window."];
        }
        $outcome = $settlement->status === SettlementStatus::Disputed ? ReconciliationOutcome::Disputed : ($actual === null ? ReconciliationOutcome::Pending : (Money::compare($actual, $expected) === 0 && $pending === 0 && $reversed === 0 ? ReconciliationOutcome::Reconciled : (Money::compare($actual, '0') > 0 && Money::compare($actual, $expected) < 0 ? ReconciliationOutcome::PartiallyReconciled : ReconciliationOutcome::Unreconciled)));

        return ['expected_amount' => $expected, 'actual_amount' => $actual, 'discrepancy' => $discrepancy, 'difference' => $discrepancy, 'is_reconciled' => $outcome === ReconciliationOutcome::Reconciled, 'status' => $outcome === ReconciliationOutcome::Reconciled ? SettlementStatus::Settled->value : ($outcome === ReconciliationOutcome::Disputed ? SettlementStatus::Disputed->value : ($actual === null ? SettlementStatus::Pending->value : SettlementStatus::Unreconciled->value)), 'outcome' => $outcome->value, 'matched_transaction_count' => $transactions->count(), 'pending_transaction_count' => $pending, 'reversed_transaction_count' => $reversed, 'issues' => $issues];
    }

    public function issuesForAgent(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $settlements = $agent->settlements()->with(['provider', 'terminal'])->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))->get();
        $issues = [];
        foreach ($settlements as $settlement) {
            foreach ($this->compare($settlement)['issues'] as $issue) {
                $issues[] = [...$issue, 'settlement_id' => $settlement->id, 'provider' => $settlement->provider->name, 'settlement_date' => $settlement->settlement_date->toDateString()];
            }
        }
        $transactions = $agent->transactions()->where('transaction_status', TransactionStatus::Successful->value)->when($from, fn ($q) => $q->whereDate('transaction_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('transaction_at', '<=', $to))->get();
        foreach ($transactions->groupBy(fn ($transaction) => implode('|', [$transaction->provider_id, $transaction->terminal_id ?? 'none', $transaction->transaction_at->toDateString()])) as $group) {
            $first = $group->first();
            $hasSettlement = $settlements->contains(fn ($settlement) => $settlement->provider_id === $first->provider_id && $settlement->terminal_id === $first->terminal_id && $settlement->settlement_date->toDateString() === $first->transaction_at->toDateString());
            if (! $hasSettlement) {
                $issues[] = ['type' => ReconciliationIssueType::MissingSettlement->value, 'message' => 'Successful transactions have no matching settlement.', 'settlement_id' => null, 'provider' => $first->provider->name, 'settlement_date' => $first->transaction_at->toDateString(), 'expected_amount' => $this->expectedSettlementFor($agent, $first->provider_id, $first->terminal_id, $first->transaction_at->toDateString())];
            }
        }

        return $issues;
    }

    public function overview(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $settlements = $agent->settlements()->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))->get();
        $counts = array_fill_keys(array_column(ReconciliationOutcome::cases(), 'value'), 0);
        $difference = '0.00';
        foreach ($settlements as $settlement) {
            $result = $this->compare($settlement);
            $counts[$result['outcome']]++;
            if ($result['discrepancy'] !== null) {
                $difference = Money::add($difference, $result['discrepancy']);
            }
        }

        return ['settlement_count' => $settlements->count(), 'outcomes' => $counts, 'unreconciled_amount' => $difference, 'issue_count' => count($this->issuesForAgent($agent, $from, $to))];
    }
}
