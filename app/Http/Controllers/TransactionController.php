<?php

namespace App\Http\Controllers;

use App\Enums\CustomerChargeSource;
use App\Enums\OnboardingState;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Services\EarningsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public function index(Request $request, EarningsService $earnings)
    {
        $agent = $request->user()->businessAgentProfile();
        if ($agent === null || $agent->onboarding_state !== OnboardingState::Completed) {
            return redirect('/dashboard');
        }
        $filters = $request->validate(['provider_id' => 'nullable|integer', 'status' => 'nullable|in:successful,pending,failed,reversed', 'from' => 'nullable|date', 'to' => 'nullable|date', 'search' => 'nullable|string|max:100']);
        $query = $agent->transactions()->with(['provider', 'terminal', 'importBatch', 'adjustments'])->latest('transaction_at');
        $query->when($filters['provider_id'] ?? null, fn ($query, $value) => $query->where('provider_id', $value));
        $query->when($filters['status'] ?? null, fn ($query, $value) => $query->where('transaction_status', $value));
        $query->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('transaction_at', '>=', $value));
        $query->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('transaction_at', '<=', $value));
        $query->when($filters['search'] ?? null, fn ($query, $value) => $query->where('external_reference', 'like', "%{$value}%"));
        $transactions = $query->paginate(20)->withQueryString();
        $transactions->getCollection()->transform(function ($transaction) use ($earnings) {
            $transaction->setAttribute('estimated_earnings', $earnings->transactionContribution($transaction));
            $transaction->setAttribute('financial_status', $earnings->transactionFinancialStatus($transaction));

            return $transaction;
        });

        return Inertia::render('Transactions/Index', ['transactions' => $transactions, 'providers' => $agent->providers()->orderBy('providers.name')->get(['providers.id', 'providers.name']), 'filters' => $filters, 'importBatches' => $agent->importBatches()->with('provider')->latest()->limit(10)->get()]);
    }

    public function show(Request $request, Transaction $transaction, EarningsService $earnings)
    {
        Gate::authorize('view', $transaction);
        $transaction->load(['provider', 'providerAccount', 'terminal', 'importBatch', 'adjustments']);
        $transaction->setAttribute('estimated_earnings', $earnings->transactionContribution($transaction));
        $transaction->setAttribute('financial_status', $earnings->transactionFinancialStatus($transaction));

        $agent = $request->user()->businessAgentProfile();
        $terminals = $request->user()->businessRole() === 'owner'
            ? Terminal::where('agent_profile_id', $agent->id)
                ->where('provider_id', $transaction->provider_id)
                ->where('active', true)
                ->where(function ($query) use ($transaction): void {
                    $query->whereNull('provider_account_id')->orWhere('provider_account_id', $transaction->provider_account_id);
                })
                ->orderBy('name')->get(['id', 'name', 'provider_id'])
            : collect();

        return Inertia::render('Transactions/Show', ['transaction' => $transaction, 'terminals' => $terminals, 'canAssignTerminal' => $request->user()->businessRole() === 'owner']);
    }

    public function updateTerminal(Request $request, Transaction $transaction)
    {
        Gate::authorize('update', $transaction);
        $agent = $request->user()->businessAgentProfile();
        $validated = $request->validate([
            'terminal_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('terminals', 'id')
                    ->where('agent_profile_id', $agent->id)
                    ->where('provider_id', $transaction->provider_id)
                    ->where('active', true),
            ],
        ]);
        $terminal = isset($validated['terminal_id']) ? Terminal::findOrFail($validated['terminal_id']) : null;
        if ($terminal !== null && $transaction->provider_account_id !== null && $terminal->provider_account_id !== null) {
            abort_unless($transaction->provider_account_id === $terminal->provider_account_id, 422, 'Choose a terminal assigned to this provider account.');
        }
        if ($terminal !== null && $transaction->provider_account_id !== null && $terminal->provider_account_id === null) {
            $terminal->update(['provider_account_id' => $transaction->provider_account_id]);
        }
        if ($transaction->terminal_id !== $terminal?->id) {
            $metadata = $transaction->metadata ?? [];
            $metadata['terminal_assignment_history'] = [
                ...array_slice((array) ($metadata['terminal_assignment_history'] ?? []), -9),
                [
                    'from_terminal_id' => $transaction->terminal_id,
                    'to_terminal_id' => $terminal?->id,
                    'assigned_at' => now()->toIso8601String(),
                ],
            ];
            $transaction->update(['terminal_id' => $terminal?->id, 'metadata' => $metadata]);
        }
        $transaction = $transaction->fresh(['provider', 'providerAccount', 'terminal']);
        if ($request->expectsJson()) {
            return response()->json($transaction);
        }

        return back()->with('success', 'Terminal assignment updated.');
    }

    public function updateCharge(Request $request, Transaction $transaction)
    {
        Gate::authorize('update', $transaction);
        abort_unless(($transaction->metadata['activity_scope'] ?? null) !== 'personal_wallet', 422, 'Customer charges do not apply to personal wallet activity.');
        $validated = $request->validate(['customer_charge_override' => 'nullable|regex:/^\d+(\.\d{1,4})?$/']);
        if ($validated['customer_charge_override'] === null) {
            $effective = $transaction->imported_customer_charge ?? $transaction->calculated_customer_charge ?? '0.00';
            $transaction->customer_charge_override = null;
            $transaction->customer_charge_source = $transaction->imported_customer_charge !== null ? CustomerChargeSource::Imported : CustomerChargeSource::Calculated;
        } else {
            $effective = BigDecimal::of($validated['customer_charge_override'])->toScale(2, RoundingMode::HalfUp)->__toString();
            $transaction->customer_charge_override = $effective;
            $transaction->customer_charge_source = CustomerChargeSource::Manual;
        }
        $transaction->customer_charge = $effective;
        $transaction->customer_charge_override_by = $validated['customer_charge_override'] === null ? null : $request->user()->id;
        $transaction->customer_charge_override_at = $validated['customer_charge_override'] === null ? null : now();
        $transaction->save();
        if ($request->expectsJson()) {
            return response()->json($transaction->fresh(['provider', 'terminal', 'chargeOverrideBy']));
        }

        return back()->with('success', 'Customer charge updated.');
    }
}
