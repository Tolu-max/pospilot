<?php

namespace App\Services;

use App\Models\BusinessShift;
use App\Support\Money;

class ShiftCashSummaryService
{
    /** @return array{opening_cash:?string,cash_received:string,cash_paid_out:string,expenses:string,expected_cash:?string,actual_cash:?string,variance:?string,variance_status:string} */
    public function forShift(BusinessShift $shift): array
    {
        $entries = $shift->cashEntries()->get(['entry_type', 'amount']);
        $cashReceived = $entries->where('entry_type', 'cash_in')->reduce(fn (string $total, $entry): string => Money::add($total, $entry->amount), '0.00');
        $cashPaidOut = $entries->where('entry_type', 'cash_out')->reduce(fn (string $total, $entry): string => Money::add($total, $entry->amount), '0.00');
        $expenses = $entries->where('entry_type', 'expense')->reduce(fn (string $total, $entry): string => Money::add($total, $entry->amount), '0.00');
        $expectedCash = $shift->opening_cash === null ? null : Money::subtract(Money::add($shift->opening_cash, $cashReceived), Money::add($cashPaidOut, $expenses));
        $actualCash = $shift->closing_cash === null ? null : (string) $shift->closing_cash;
        $variance = $expectedCash === null || $actualCash === null ? null : Money::subtract($actualCash, $expectedCash);

        return [
            'opening_cash' => $shift->opening_cash === null ? null : (string) $shift->opening_cash,
            'cash_received' => $cashReceived,
            'cash_paid_out' => $cashPaidOut,
            'expenses' => $expenses,
            'expected_cash' => $expectedCash,
            'actual_cash' => $actualCash,
            'variance' => $variance,
            'variance_status' => $variance === null ? 'unknown' : (Money::compare($variance, '0.00') === 0 ? 'matched' : (Money::compare($variance, '0.00') < 0 ? 'below_expected' : 'above_expected')),
        ];
    }
}
