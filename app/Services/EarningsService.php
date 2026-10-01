<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\AgentProfile;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class EarningsService
{
    public function __construct(
        private readonly ReconciliationService $reconciliation = new ReconciliationService,
        private readonly TransactionFinancialComponentsService $components = new TransactionFinancialComponentsService,
        private readonly FinancialDataStatusService $financialStatus = new FinancialDataStatusService,
    ) {}

    public function summarize(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $transactions = $this->successfulTransactions($agent, $from, $to);
        $totals = $this->financialTotals($transactions);
        $expenses = $this->totalExpenses($agent, $from, $to);

        return ['customer_charges' => $totals['customer_charges'], 'provider_fees' => $totals['provider_fees'], 'other_transaction_credits' => $totals['other_credits'], 'operating_expenses' => $expenses, 'estimated_net_earnings' => Money::subtract(Money::add(Money::subtract($totals['customer_charges'], $totals['provider_fees']), $totals['other_credits']), $expenses), 'successful_transaction_count' => $transactions->count(), ...$this->financialStatus->summarize($transactions)];
    }

    public function today(AgentProfile $agent): array
    {
        return $this->summarize($agent, CarbonImmutable::today(), CarbonImmutable::today());
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

    public function totalCustomerCharges(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): string
    {
        return $this->financialTotals($this->successfulTransactions($agent, $from, $to))['customer_charges'];
    }

    public function totalProviderFees(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): string
    {
        return $this->financialTotals($this->successfulTransactions($agent, $from, $to))['provider_fees'];
    }

    public function transactionContribution(Transaction $transaction): string
    {
        if (($transaction->metadata['activity_scope'] ?? null) === 'personal_wallet') {
            return '0.00';
        }

        if ($transaction->transaction_status !== TransactionStatus::Successful) {
            return '0.00';
        }

        $totals = $this->components->summarize($transaction);

        return Money::add(Money::subtract($totals['customer_charges'], $totals['provider_fees']), $totals['other_credits']);
    }

    /** @return array{financial_data_status:string,earnings_status:string,is_final:bool,reasons:list<string>,customer_charge_source:?string,provider_fee_supplied:bool,provider_fee_components_complete:bool} */
    public function transactionFinancialStatus(Transaction $transaction): array
    {
        return $this->financialStatus->forTransaction($transaction);
    }

    public function totalExpenses(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): string
    {
        return $agent->expenses()->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))->get()->reduce(fn (string $total, $expense) => Money::add($total, $expense->amount), '0.00');
    }

    public function byProvider(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $transactions = $this->successfulTransactions($agent, $from, $to)->load('provider');
        $groups = $transactions->groupBy('provider_id');
        $rows = [];
        foreach ($groups as $providerId => $items) {
            $totals = $this->financialTotals($items);
            $settlementDifference = $agent->settlements()->where('provider_id', $providerId)->get()->reduce(fn (string $total, $settlement) => Money::add($total, $this->reconciliation->compare($settlement)['discrepancy'] ?? '0.00'), '0.00');
            $unreconciled = $agent->settlements()->where('provider_id', $providerId)->get()->filter(fn ($settlement) => $this->reconciliation->compare($settlement)['outcome'] !== 'reconciled')->count();
            $rows[] = ['provider_id' => (int) $providerId, 'provider' => $items->first()->provider->name, 'transaction_volume' => $this->sum($items, 'amount'), 'transaction_count' => $items->count(), 'provider_fees' => $totals['provider_fees'], 'customer_charges' => $totals['customer_charges'], 'other_transaction_credits' => $totals['other_credits'], 'estimated_earnings' => Money::add(Money::subtract($totals['customer_charges'], $totals['provider_fees']), $totals['other_credits']), 'settlement_discrepancy' => $settlementDifference, 'unreconciled_count' => $unreconciled, ...$this->financialStatus->summarize($items)];
        }

        return $rows;
    }

    public function byTerminal(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return $this->successfulTransactions($agent, $from, $to)->load(['terminal', 'provider'])->groupBy('terminal_id')->map(function ($items, $terminalId): array {
            $totals = $this->financialTotals($items);
            $financialStatus = $this->financialStatus->summarize($items);
            $providerFeeKnown = ! collect($financialStatus['provisional_reasons'])->contains(fn (array $reason): bool => in_array($reason['code'], ['provider_fee_missing', 'provider_fee_components_incomplete'], true));

            return ['terminal_id' => $terminalId, 'terminal' => $items->first()->terminal?->name ?? 'Terminal not mapped', 'provider' => $items->first()->provider->name, 'transaction_volume' => $this->sum($items, 'amount'), 'transaction_count' => $items->count(), 'customer_charges' => $totals['customer_charges'], 'provider_fees' => $totals['provider_fees'], 'provider_fee_known' => $providerFeeKnown, 'other_transaction_credits' => $totals['other_credits'], 'estimated_earnings' => Money::add(Money::subtract($totals['customer_charges'], $totals['provider_fees']), $totals['other_credits']), ...$financialStatus];
        })->values()->all();
    }

    /** @return array{data:list<array<string,mixed>>,unallocated_expenses:string,expense_attribution_complete:bool} */
    public function terminalProfitability(AgentProfile $agent, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $expenses = $agent->expenses()
            ->when($from, fn ($query) => $query->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('expense_date', '<=', $to))
            ->get(['terminal_id', 'amount']);
        $unallocatedExpenses = $expenses->whereNull('terminal_id')->reduce(fn (string $total, $expense): string => Money::add($total, $expense->amount), '0.00');
        $allocatedExpenses = $expenses->whereNotNull('terminal_id')->groupBy('terminal_id');
        $expenseAttributionComplete = Money::compare($unallocatedExpenses, '0.00') === 0;
        $rows = collect($this->byTerminal($agent, $from, $to))->map(function (array $row) use ($allocatedExpenses, $expenseAttributionComplete): array {
            $terminalExpenses = ($allocatedExpenses->get($row['terminal_id']) ?? collect())->reduce(fn (string $total, $expense): string => Money::add($total, $expense->amount), '0.00');

            return [
                ...$row,
                'allocated_expenses' => $terminalExpenses,
                'estimated_net_after_expenses' => Money::subtract($row['estimated_earnings'], $terminalExpenses),
                'expense_attribution_complete' => $expenseAttributionComplete,
                'is_final' => $row['is_final'] && $expenseAttributionComplete,
            ];
        })->values()->all();

        return ['data' => $rows, 'unallocated_expenses' => $unallocatedExpenses, 'expense_attribution_complete' => $expenseAttributionComplete];
    }

    private function successfulTransactions(AgentProfile $agent, ?CarbonImmutable $from, ?CarbonImmutable $to): Collection
    {
        return $agent->transactions()->posFinancial()->with('adjustments')->where('transaction_status', TransactionStatus::Successful->value)->when($from, fn ($q) => $q->whereDate('transaction_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('transaction_at', '<=', $to))->get();
    }

    /** @param Collection<int, Transaction> $transactions
     * @return array{customer_charges:string,provider_fees:string,other_credits:string}
     */
    private function financialTotals(Collection $transactions): array
    {
        return $transactions->reduce(function (array $totals, Transaction $transaction): array {
            $components = $this->components->summarize($transaction);

            return [
                'customer_charges' => Money::add($totals['customer_charges'], $components['customer_charges']),
                'provider_fees' => Money::add($totals['provider_fees'], $components['provider_fees']),
                'other_credits' => Money::add($totals['other_credits'], $components['other_credits']),
            ];
        }, ['customer_charges' => '0.00', 'provider_fees' => '0.00', 'other_credits' => '0.00']);
    }

    private function sum(Collection $items, string $field): string
    {
        return $items->reduce(fn (string $total, $item): string => Money::add($total, $item->{$field} ?? '0.00'), '0.00');
    }
}
