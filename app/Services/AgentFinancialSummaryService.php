<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\AgentProfile;
use App\Support\Money;
use Carbon\CarbonImmutable;

final class AgentFinancialSummaryService
{
    public function __construct(private readonly EarningsService $earnings, private readonly ReconciliationService $reconciliation) {}

    public function summarize(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $transactions = $agent->transactions()->when($from, fn ($q) => $q->whereDate('transaction_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('transaction_at', '<=', $to))->get();
        $earnings = $this->earnings->summarize($agent, $from, $to);
        $successful = $transactions->where('transaction_status', TransactionStatus::Successful);
        $issues = $this->reconciliation->issuesForAgent($agent, $from, $to);
        $overview = $this->reconciliation->overview($agent, $from, $to);

        return ['from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'transaction_volume' => $successful->reduce(fn (string $total, $transaction) => Money::add($total, $transaction->amount), '0.00'), 'successful_transaction_count' => $earnings['successful_transaction_count'], 'customer_charges_collected' => $earnings['customer_charges'], 'provider_fees' => $earnings['provider_fees'], 'other_transaction_credits' => $earnings['other_transaction_credits'], 'expenses' => $earnings['operating_expenses'], 'estimated_earnings' => $earnings['estimated_net_earnings'], 'estimated_net_earnings' => $earnings['estimated_net_earnings'], 'earnings_status' => $earnings['earnings_status'], 'financial_data_status' => $earnings['financial_data_status'], 'is_final' => $earnings['is_final'], 'provisional_transaction_count' => $earnings['provisional_transaction_count'], 'provisional_reasons' => $earnings['provisional_reasons'], 'calculated_transaction_count' => $earnings['calculated_transaction_count'], 'manually_overridden_transaction_count' => $earnings['manually_overridden_transaction_count'], 'complete_verified_transaction_count' => $earnings['complete_verified_transaction_count'], 'pending_transaction_value' => $transactions->where('transaction_status', TransactionStatus::Pending)->reduce(fn (string $total, $transaction) => Money::add($total, $transaction->amount), '0.00'), 'reversed_transaction_value' => $transactions->where('transaction_status', TransactionStatus::Reversed)->reduce(fn (string $total, $transaction) => Money::add($total, $transaction->amount), '0.00'), 'attention_transaction_count' => $transactions->filter(fn ($transaction) => in_array($transaction->transaction_status, [TransactionStatus::Pending, TransactionStatus::Reversed], true) || in_array($transaction->settlement_status?->value, ['unreconciled', 'disputed'], true))->count(), 'unreconciled_amount' => $overview['unreconciled_amount'], 'reconciliation_issue_count' => count($issues)];
    }

    public function today(AgentProfile $agent): array
    {
        $date = CarbonImmutable::today();

        return $this->summarize($agent, $date, $date);
    }

    public function yesterday(AgentProfile $agent): array
    {
        $date = CarbonImmutable::yesterday();

        return $this->summarize($agent, $date, $date);
    }

    public function dateRange(AgentProfile $agent, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->summarize($agent, $from, $to);
    }
}
