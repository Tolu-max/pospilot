<?php

namespace App\Http\Controllers;

use App\Models\BusinessShift;
use App\Models\Expense;
use App\Models\Terminal;
use App\Services\SecurityEventRecorder;
use App\Services\ShiftCashSummaryService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class StaffShiftController extends Controller
{
    public function dashboard(Request $request, ShiftCashSummaryService $cashSummary): JsonResponse
    {
        $membership = $request->user()->teamMembership;
        abort_unless($membership?->is_active, 403);
        $activeShift = $membership->shifts()->where('status', 'active')->with('terminal:id,name')->first();
        $transactions = $activeShift ? $this->shiftTransactions($activeShift)->get()->map(fn ($transaction): array => [
            'id' => $transaction->id,
            'reference' => $transaction->external_reference,
            'amount' => $transaction->amount,
            'status' => $transaction->transaction_status?->value ?? $transaction->transaction_status,
            'transaction_at' => $transaction->transaction_at,
            'terminal' => $activeShift->terminal->name,
        ]) : collect();

        return response()->json([
            'staff_name' => $request->user()->name,
            'role' => $membership->role,
            'active_shift' => $activeShift,
            'cash_summary' => $activeShift ? $cashSummary->forShift($activeShift) : null,
            'assigned_terminals' => $membership->terminals()->where('terminals.active', true)->with('provider:id,name')->orderBy('name')->get(['terminals.id', 'terminals.name', 'terminals.provider_id']),
            'transactions' => $transactions,
            'recent_shifts' => $membership->shifts()->with('terminal:id,name')->latest('started_at')->limit(5)->get()->map(fn (BusinessShift $shift): array => [...$shift->toArray(), 'cash_summary' => $cashSummary->forShift($shift)]),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $membership = $request->user()->teamMembership;
        abort_unless($membership?->is_active, 403);
        $validated = $request->validate([
            'terminal_id' => ['required', 'integer', Rule::exists('terminals', 'id')->where('agent_profile_id', $membership->agent_profile_id)->where('active', true)],
            'opening_cash' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        return DB::transaction(function () use ($membership, $validated): JsonResponse {
            $membership = $membership->newQuery()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ($membership->shifts()->where('status', 'active')->exists()) {
                return response()->json(['message' => 'End your current shift before starting another.'], 409);
            }
            $terminal = Terminal::query()->whereKey($validated['terminal_id'])->lockForUpdate()->firstOrFail();
            abort_unless($terminal->agent_profile_id === $membership->agent_profile_id && $terminal->active
                && $membership->terminals()->where('terminals.id', $terminal->id)->exists(), 404);
            if (BusinessShift::where('terminal_id', $terminal->id)->where('status', 'active')->exists()) {
                return response()->json(['message' => 'Another attendant is already working on this terminal.'], 409);
            }
            $shift = $membership->shifts()->create([
                'agent_profile_id' => $membership->agent_profile_id,
                'terminal_id' => $validated['terminal_id'],
                'started_at' => now(),
                'opening_cash' => $validated['opening_cash'] ?? null,
                'status' => 'active',
            ]);

            return response()->json($shift->load('terminal:id,name'), 201);
        });
    }

    public function terminals(Request $request): JsonResponse
    {
        $membership = $request->user()->teamMembership;
        abort_unless($membership?->is_active, 403);

        return response()->json(['data' => $membership->terminals()->where('terminals.active', true)->with('provider:id,name')->orderBy('name')->get(['terminals.id', 'terminals.name', 'terminals.provider_id'])]);
    }

    public function close(Request $request, BusinessShift $shift, SecurityEventRecorder $events, ShiftCashSummaryService $cashSummary): JsonResponse
    {
        $membership = $request->user()->teamMembership;
        abort_unless($membership?->is_active && $shift->business_membership_id === $membership->id, 404);
        abort_unless($shift->status === 'active', 422, 'This shift has already ended.');
        $validated = $request->validate([
            'closing_cash' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'closing_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $shift->update([
            'closing_cash' => $validated['closing_cash'],
            'closing_notes' => $validated['closing_notes'] ?? null,
            'ended_at' => now(),
            'status' => 'closed',
        ]);
        $shift->refresh();
        $events->record($request->user(), 'shift_closed', $request);

        return response()->json([...$shift->load('terminal:id,name')->toArray(), 'cash_summary' => $cashSummary->forShift($shift)]);
    }

    public function transactions(Request $request, BusinessShift $shift): JsonResponse
    {
        $membership = $request->user()->teamMembership;
        abort_unless($membership?->is_active && $shift->business_membership_id === $membership->id, 404);

        return response()->json(['data' => $this->shiftTransactions($shift)->latest('transaction_at')->limit(200)->get()->map(fn ($transaction): array => [
            'id' => $transaction->id,
            'reference' => $transaction->external_reference,
            'amount' => $transaction->amount,
            'status' => $transaction->transaction_status?->value ?? $transaction->transaction_status,
            'transaction_at' => $transaction->transaction_at,
            'terminal' => $shift->terminal->name,
        ])]);
    }

    public function cashActivity(Request $request, BusinessShift $shift): JsonResponse
    {
        $membership = $request->user()->teamMembership;
        abort_unless($membership?->is_active && $shift->business_membership_id === $membership->id, 404);
        abort_unless($shift->status === 'active', 422, 'Cash activity can only be recorded during an active shift.');
        $validated = $request->validate([
            'entry_type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'category' => ['required', Rule::in(['cash_float', 'cash_payout', 'other'])],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $entry = $shift->cashEntries()->create([
            ...$validated,
            'business_membership_id' => $membership->id,
            'recorded_at' => now(),
        ]);

        return response()->json($entry, 201);
    }

    public function expense(Request $request, BusinessShift $shift): JsonResponse
    {
        $membership = $request->user()->teamMembership;
        abort_unless($membership?->is_active && $shift->business_membership_id === $membership->id, 404);
        abort_unless($shift->status === 'active', 422, 'Expenses can only be recorded during an active shift.');
        $validated = $request->validate([
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'category' => ['required', Rule::in(['transport', 'power', 'staff', 'cash handling', 'miscellaneous'])],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $expense = DB::transaction(function () use ($shift, $membership, $validated): Expense {
            $expense = Expense::create([
                ...$validated,
                'agent_profile_id' => $membership->agent_profile_id,
                'business_membership_id' => $membership->id,
                'terminal_id' => $shift->terminal_id,
                'business_shift_id' => $shift->id,
                'expense_date' => today(),
            ]);
            $shift->cashEntries()->create([
                'business_membership_id' => $membership->id,
                'entry_type' => 'expense',
                'amount' => $validated['amount'],
                'category' => $validated['category'],
                'description' => $validated['description'] ?? null,
                'recorded_at' => now(),
            ]);

            return $expense;
        });

        return response()->json($expense, 201);
    }

    public function reportIssue(Request $request, BusinessShift $shift, SecurityEventRecorder $events): JsonResponse
    {
        $membership = $request->user()->teamMembership;
        abort_unless($membership?->is_active && $shift->business_membership_id === $membership->id, 404);
        $validated = $request->validate(['subject' => ['required', 'string', 'max:160'], 'description' => ['required', 'string', 'max:2000']]);
        $issue = $shift->issues()->create([
            ...$validated,
            'business_membership_id' => $membership->id,
            'status' => 'open',
        ]);
        $events->record($request->user(), 'shift_issue_reported', $request);

        return response()->json(['id' => $issue->id, 'subject' => $issue->subject, 'status' => $issue->status], 201);
    }

    private function shiftTransactions(BusinessShift $shift): HasMany
    {
        return $shift->agentProfile->transactions()
            ->posFinancial()
            ->where('terminal_id', $shift->terminal_id)
            ->where('transaction_at', '>=', $shift->started_at)
            ->when($shift->ended_at, fn ($query, $endedAt) => $query->where('transaction_at', '<=', $endedAt));
    }
}
