<?php

namespace App\Http\Controllers;

use App\Enums\CustomerChargeSource;
use App\Models\Transaction;
use App\Services\EarningsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public function index(Request $request, EarningsService $earnings)
    {
        $agent = $request->user()->agentProfile;
        abort_unless($agent, 404, 'Agent profile not found.');
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

        return Inertia::render('Transactions/Index', ['transactions' => $transactions, 'providers' => $agent->providers()->orderBy('name')->get(['providers.id', 'providers.name']), 'filters' => $filters, 'importBatches' => $agent->importBatches()->with('provider')->latest()->limit(10)->get()]);
    }

    public function show(Request $request, Transaction $transaction, EarningsService $earnings)
    {
        Gate::authorize('view', $transaction);
        $transaction->load(['provider', 'terminal', 'importBatch', 'adjustments']);
        $transaction->setAttribute('estimated_earnings', $earnings->transactionContribution($transaction));
        $transaction->setAttribute('financial_status', $earnings->transactionFinancialStatus($transaction));

        return Inertia::render('Transactions/Show', ['transaction' => $transaction]);
    }

    public function updateCharge(Request $request, Transaction $transaction)
    {
        Gate::authorize('update', $transaction);
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
