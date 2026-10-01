<?php

namespace App\Services;

use App\Enums\DailyClosingStatus;
use App\Enums\DailyClosingVarianceStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\AgentProfile;
use App\Models\DailyClosing;
use App\Models\ProviderBalanceSnapshot;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DailyClosingService
{
    public function __construct(private readonly TransactionFinancialComponentsService $components = new TransactionFinancialComponentsService) {}

    public function preview(AgentProfile $agent, CarbonImmutable $date): array
    {
        $closing = $agent->dailyClosings()->whereDate('closing_date', $date)->with('providerBalanceSnapshots.provider', 'providerBalanceSnapshots.terminal')->first();
        $values = $this->calculate($agent, $date, $closing);

        return ['closing' => $closing, ...$values];
    }

    public function saveDraft(AgentProfile $agent, CarbonImmutable $date, array $input): DailyClosing
    {
        return DB::transaction(function () use ($agent, $date, $input) {
            $closing = $agent->dailyClosings()->whereDate('closing_date', $date)->lockForUpdate()->first();
            if ($closing?->status === DailyClosingStatus::Finalized) {
                throw new InvalidArgumentException('A finalized daily closing cannot be changed.');
            }
            $closing ??= $agent->dailyClosings()->create(['closing_date' => $date, 'status' => DailyClosingStatus::Draft->value, 'variance_status' => DailyClosingVarianceStatus::Unresolved->value]);
            $closing->update(array_filter(['opening_cash' => $input['opening_cash'] ?? null, 'entered_closing_cash' => $input['entered_closing_cash'] ?? null, 'notes' => $input['notes'] ?? null], fn ($value) => $value !== null));
            $this->recalculate($closing->fresh());

            return $closing->fresh(['providerBalanceSnapshots.provider', 'providerBalanceSnapshots.terminal']);
        });
    }

    public function saveProviderBalance(AgentProfile $agent, DailyClosing $closing, array $input): ProviderBalanceSnapshot
    {
        $this->assertOwnership($agent, $closing);
        if ($closing->status === DailyClosingStatus::Finalized) {
            throw new InvalidArgumentException('A finalized daily closing cannot be changed.');
        }
        $providerId = (int) $input['provider_id'];
        $terminalId = $input['terminal_id'] ?? null;
        if ($terminalId !== null) {
            $terminal = Terminal::where('id', $terminalId)->where('agent_profile_id', $agent->id)->firstOrFail();
            if ($terminal->provider_id !== $providerId) {
                throw new InvalidArgumentException('The terminal does not belong to the selected provider.');
            }
        }
        $expected = $this->expectedForSnapshot($agent, $closing->closing_date->toImmutable(), $providerId, $terminalId);
        $actual = $input['actual_balance'] ?? null;
        $snapshot = $closing->providerBalanceSnapshots()->updateOrCreate(['provider_id' => $providerId, 'terminal_id' => $terminalId], ['expected_balance' => $expected, 'actual_balance' => $actual, 'difference' => $actual === null ? null : Money::subtract($actual, $expected), 'source' => TransactionSource::Manual->value, 'metadata' => $input['metadata'] ?? null]);
        $this->recalculate($closing->fresh());

        return $snapshot->fresh(['provider', 'terminal']);
    }

    public function finalize(AgentProfile $agent, DailyClosing $closing, int $userId): DailyClosing
    {
        $this->assertOwnership($agent, $closing);

        return DB::transaction(function () use ($closing, $userId) {
            $locked = DailyClosing::whereKey($closing->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === DailyClosingStatus::Finalized) {
                throw new InvalidArgumentException('This daily closing has already been finalized.');
            }
            $this->recalculate($locked);
            $locked->refresh();
            $calculation = $this->calculate($locked->agentProfile, $locked->closing_date->toImmutable(), $locked);
            if ($calculation['requires_attention']) {
                throw new InvalidArgumentException('Enter all required actual balances and closing cash before finalizing.');
            }
            $locked->update(['status' => DailyClosingStatus::Finalized->value, 'closed_by' => $userId, 'closed_at' => now(), 'variance_status' => $calculation['variance_status']]);

            return $locked->fresh(['providerBalanceSnapshots.provider', 'providerBalanceSnapshots.terminal', 'closedBy']);
        });
    }

    public function recalculate(DailyClosing $closing): DailyClosing
    {
        foreach ($closing->providerBalanceSnapshots as $snapshot) {
            $expected = $this->expectedForSnapshot($closing->agentProfile, $closing->closing_date->toImmutable(), $snapshot->provider_id, $snapshot->terminal_id);
            $snapshot->update(['expected_balance' => $expected, 'difference' => $snapshot->actual_balance === null ? null : Money::subtract($snapshot->actual_balance, $expected)]);
        }
        $values = $this->calculate($closing->agentProfile, $closing->closing_date->toImmutable(), $closing);
        $closing->update(['expected_cash' => $values['expected_cash'], 'expected_electronic_position' => $values['expected_electronic_position'], 'actual_electronic_position' => $values['actual_electronic_position'], 'transaction_volume' => $values['transaction_volume'], 'customer_charges' => $values['customer_charges'], 'provider_fees' => $values['provider_fees'], 'expenses' => $values['expenses'], 'total_variance' => $values['total_variance'], 'variance_status' => $values['variance_status']]);

        return $closing->fresh();
    }

    private function calculate(AgentProfile $agent, CarbonImmutable $date, ?DailyClosing $closing): array
    {
        $transactions = $agent->transactions()->posFinancial()->with('adjustments')->whereDate('transaction_at', $date)->get();
        $successful = $transactions->where('transaction_status', TransactionStatus::Successful);
        $pendingCount = $transactions->where('transaction_status', TransactionStatus::Pending)->count();
        $reversedCount = $transactions->where('transaction_status', TransactionStatus::Reversed)->count();
        $volume = $this->sum($successful, 'amount');
        $charges = $this->financialTotal($successful, 'customer_charges');
        $fees = $this->financialTotal($successful, 'provider_fees');
        $expenses = $agent->expenses()->whereDate('expense_date', $date)->get()->reduce(fn (string $total, $expense) => Money::add($total, $expense->amount), '0.00');
        $cashEffect = '0.00';
        $electronicEffect = '0.00';
        foreach ($successful as $transaction) {
            $effect = $this->transactionEffect($transaction);
            $financials = $this->components->summarize($transaction);
            $cashEffect = Money::add($cashEffect, ($effect['cash'] ?? 'amount') === '-amount' ? Money::subtract($financials['customer_charges'], $transaction->amount) : Money::add($transaction->amount, $financials['customer_charges']));
            $electronicEffect = Money::add($electronicEffect, $this->electronicEffect($transaction, $effect));
        }
        $expectedCash = $closing?->opening_cash === null ? null : Money::subtract(Money::add($closing->opening_cash, $cashEffect), $expenses);
        $snapshots = $closing?->providerBalanceSnapshots ?? collect();
        $actualElectronic = $snapshots->whereNotNull('actual_balance')->count() === 0 ? null : $snapshots->whereNotNull('actual_balance')->reduce(fn (string $total, $snapshot) => Money::add($total, $snapshot->actual_balance), '0.00');
        $expectedElectronic = $electronicEffect;
        $cashVariance = $expectedCash !== null && $closing?->entered_closing_cash !== null ? Money::subtract($closing->entered_closing_cash, $expectedCash) : null;
        $electronicVariance = $actualElectronic === null ? null : Money::subtract($actualElectronic, $expectedElectronic);
        $totalVariance = Money::add($cashVariance ?? '0.00', $electronicVariance ?? '0.00');
        $allProviderBalancesEntered = $this->providerBalancesComplete($successful, $snapshots);
        $varianceStatus = $this->varianceStatus($totalVariance, $cashVariance === null && $expectedCash !== null || ! $allProviderBalancesEntered);
        $reasons = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->actual_balance !== null) {
                $difference = Money::subtract($snapshot->actual_balance, $this->expectedForSnapshot($agent, $date, $snapshot->provider_id, $snapshot->terminal_id));
                if (Money::compare($difference, '0') < 0) {
                    $reasons[] = ['type' => 'provider_balance_below_expected', 'provider_id' => $snapshot->provider_id, 'terminal_id' => $snapshot->terminal_id, 'difference' => $difference];
                } if (Money::compare($difference, '0') > 0) {
                    $reasons[] = ['type' => 'provider_balance_above_expected', 'provider_id' => $snapshot->provider_id, 'terminal_id' => $snapshot->terminal_id, 'difference' => $difference];
                }
            }
        } if ($cashVariance !== null && Money::compare($cashVariance, '0') !== 0) {
            $reasons[] = ['type' => 'cash_mismatch', 'difference' => $cashVariance];
        } if ($pendingCount > 0) {
            $reasons[] = ['type' => 'pending_transaction', 'count' => $pendingCount];
        } if ($reversedCount > 0) {
            $reasons[] = ['type' => 'reversal_affecting_expected_position', 'count' => $reversedCount];
        } if (Money::compare($totalVariance, '0') !== 0 && $reasons === []) {
            $reasons[] = ['type' => 'unexplained_variance', 'difference' => $totalVariance];
        }

        return ['expected_cash' => $expectedCash, 'expected_electronic_position' => $expectedElectronic, 'actual_electronic_position' => $actualElectronic, 'transaction_volume' => $volume, 'customer_charges' => $charges, 'provider_fees' => $fees, 'expenses' => $expenses, 'total_variance' => $totalVariance, 'variance_status' => $varianceStatus, 'requires_attention' => ($expectedCash !== null && $cashVariance === null) || ! $allProviderBalancesEntered, 'pending_transaction_count' => $pendingCount, 'reversed_transaction_count' => $reversedCount, 'reasons' => $reasons];
    }

    private function providerBalancesComplete(Collection $successful, Collection $snapshots): bool
    {
        foreach ($successful->groupBy('provider_id') as $providerId => $transactions) {
            $providerSnapshot = $snapshots->first(fn ($snapshot): bool => (int) $snapshot->provider_id === (int) $providerId && $snapshot->terminal_id === null);
            if ($providerSnapshot !== null) {
                if ($providerSnapshot->actual_balance === null) {
                    return false;
                }

                continue;
            }
            if ($transactions->contains(fn (Transaction $transaction): bool => $transaction->terminal_id === null)) {
                return false;
            }
            foreach ($transactions->pluck('terminal_id')->unique() as $terminalId) {
                $terminalSnapshot = $snapshots->first(fn ($snapshot): bool => (int) $snapshot->provider_id === (int) $providerId && (int) $snapshot->terminal_id === (int) $terminalId);
                if ($terminalSnapshot === null || $terminalSnapshot->actual_balance === null) {
                    return false;
                }
            }
        }

        return true;
    }

    private function expectedForSnapshot(AgentProfile $agent, CarbonImmutable $date, int $providerId, ?int $terminalId): string
    {
        $transactions = $agent->transactions()->posFinancial()->with('adjustments')->where('provider_id', $providerId)->whereDate('transaction_at', $date)->where('transaction_status', TransactionStatus::Successful->value)->when($terminalId, fn ($q) => $q->where('terminal_id', $terminalId))->get();

        return $transactions->reduce(function (string $total, $transaction): string {
            $financials = $this->components->summarize($transaction);

            return Money::add($total, Money::subtract($transaction->amount, $financials['net_provider_deduction']));
        }, '0.00');
    }

    private function transactionEffect(Transaction $transaction): array
    {
        return config('pospilot.daily_closing.transaction_effects.'.$transaction->transaction_type) ?: config('pospilot.daily_closing.transaction_effects.transfer');
    }

    private function electronicEffect(Transaction $transaction, array $effect): string
    {
        $financials = $this->components->summarize($transaction);
        $net = Money::subtract($transaction->amount, $financials['net_provider_deduction']);

        return ($effect['electronic'] ?? 'net') === '-net' ? Money::subtract('0.00', $net) : $net;
    }

    private function varianceStatus(string $variance, bool $incomplete): string
    {
        if ($incomplete) {
            return DailyClosingVarianceStatus::Unresolved->value;
        } $absolute = ltrim($variance, '-');
        if (Money::compare($absolute, '0.00') === 0) {
            return DailyClosingVarianceStatus::Balanced->value;
        } if (Money::compare($absolute, config('pospilot.daily_closing.small_variance_threshold')) <= 0) {
            return DailyClosingVarianceStatus::SmallVariance->value;
        } if (Money::compare($absolute, config('pospilot.daily_closing.review_variance_threshold')) <= 0) {
            return DailyClosingVarianceStatus::NeedsReview->value;
        }

        return DailyClosingVarianceStatus::Unresolved->value;
    }

    private function assertOwnership(AgentProfile $agent, DailyClosing $closing): void
    {
        if ($closing->agent_profile_id !== $agent->id) {
            abort(404);
        }
    }

    private function sum(Collection $items, string $field): string
    {
        return $items->reduce(fn (string $total, $item) => Money::add($total, $item->{$field} ?? '0.00'), '0.00');
    }

    private function financialTotal(Collection $items, string $field): string
    {
        return $items->reduce(fn (string $total, $transaction): string => Money::add($total, $this->components->summarize($transaction)[$field]), '0.00');
    }
}
