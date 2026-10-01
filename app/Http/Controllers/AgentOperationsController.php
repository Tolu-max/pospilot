<?php

namespace App\Http\Controllers;

use App\Enums\ChargeType;
use App\Enums\OnboardingState;
use App\Models\AgentProfile;
use App\Models\ChargeRule;
use App\Models\Expense;
use App\Models\ImportBatch;
use App\Models\Provider;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Services\ChargeCalculationService;
use App\Services\EarningsService;
use App\Services\ProviderCapabilityService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AgentOperationsController extends Controller
{
    public function profile(Request $request)
    {
        return response()->json($this->agent($request)->load('user'));
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate(['business_name' => ['sometimes', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'country' => ['sometimes', 'string', 'max:100'], 'currency' => ['sometimes', 'string', 'size:3'], 'location' => ['nullable', 'string', 'max:255'], 'onboarding_state' => ['sometimes', Rule::enum(OnboardingState::class)], 'selected_provider_slugs' => ['sometimes', 'array', 'max:4'], 'selected_provider_slugs.*' => ['string', Rule::in(['moniepoint', 'opay', 'palmpay', 'other'])]]);
        $agent = $request->user()->businessAgentProfile();
        if ($agent === null) {
            $agent = AgentProfile::create(['user_id' => $request->user()->id, 'business_name' => $validated['business_name'] ?? 'POS Business', 'country' => 'Nigeria', 'currency' => 'NGN']);
            $request->user()->setRelation('agentProfile', $agent);
        }
        $agent->update($validated);

        return response()->json($agent->fresh());
    }

    public function providers(ProviderCapabilityService $capabilities)
    {
        Provider::firstOrCreate(['slug' => 'other'], ['name' => 'Generic / Other', 'status' => 'active']);
        $providers = Provider::where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug', 'status'])->map(fn (Provider $provider) => [...$provider->toArray(), 'capabilities' => $capabilities->for($provider)]);

        return response()->json(['data' => $providers]);
    }

    public function connections(Request $request)
    {
        $connections = $this->agent($request)->providerConnections()->with('provider')->get()->map(fn (ProviderConnection $connection) => ['id' => $connection->id, 'provider' => $connection->provider, 'connection_type' => $connection->connection_type?->value, 'connection_status' => $connection->connection_status?->value, 'connection_state' => $this->connectionState($connection), 'provider_account_identifier' => $connection->provider_account_identifier, 'provider_merchant_identifier' => $connection->provider_merchant_identifier, 'last_synced_at' => $connection->last_synced_at, 'last_sync_status' => $connection->last_sync_status, 'last_sync_error' => $connection->last_sync_error, 'last_imported_at' => $connection->last_synced_at, 'configured_terminal_count' => Terminal::where('agent_profile_id', $connection->agent_profile_id)->where('provider_id', $connection->provider_id)->count()]);

        return response()->json(['data' => $connections]);
    }

    public function terminals(Request $request)
    {
        $terminals = $this->agent($request)->terminals()->with('provider')->orderBy('name');
        if ($request->user()->businessRole() === 'manager') {
            return response()->json(['data' => $terminals->get(['id', 'name', 'provider_id', 'active'])->map(fn (Terminal $terminal): array => [...$terminal->toArray(), 'provider' => $terminal->provider->only(['id', 'name'])])]);
        }

        return response()->json(['data' => $terminals->get()]);
    }

    public function storeTerminal(Request $request)
    {
        $agent = $this->agent($request);
        $validated = $request->validate([
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'provider_account_id' => ['nullable', 'integer', Rule::exists('provider_accounts', 'id')->where('agent_profile_id', $agent->id)],
            'name' => ['required', 'string', 'max:255'],
            'terminal_identifier' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (isset($validated['provider_account_id'])) {
            abort_unless(ProviderAccount::whereKey($validated['provider_account_id'])->where('provider_id', $validated['provider_id'])->exists(), 422, 'Choose an account for this provider.');
        } else {
            $accountIds = $agent->providerAccounts()->where('provider_id', $validated['provider_id'])->pluck('id');
            if ($accountIds->count() === 1) {
                $validated['provider_account_id'] = $accountIds->first();
            }
        }
        $terminal = $agent->terminals()->create($validated);

        return response()->json($terminal->load(['provider', 'providerAccount']), 201);
    }

    public function updateTerminal(Request $request, Terminal $terminal)
    {
        $agent = $this->agent($request);
        abort_unless($terminal->agent_profile_id === $agent->id, 404);
        $validated = $request->validate(['provider_id' => ['sometimes', 'integer', 'exists:providers,id'], 'name' => ['sometimes', 'string', 'max:255'], 'terminal_identifier' => ['nullable', 'string', 'max:255'], 'active' => ['sometimes', 'boolean']]);
        $terminal->update($validated);

        return response()->json($terminal->fresh('provider'));
    }

    public function toggleTerminal(Request $request, Terminal $terminal)
    {
        $agent = $this->agent($request);
        abort_unless($terminal->agent_profile_id === $agent->id, 404);
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $terminal->update($validated);

        return response()->json($terminal->fresh());
    }

    public function deleteTerminal(Request $request, Terminal $terminal)
    {
        $agent = $this->agent($request);
        abort_unless($terminal->agent_profile_id === $agent->id, 404);
        if ($terminal->transactions()->exists() || $terminal->settlements()->exists()) {
            return response()->json(['message' => 'A terminal with financial records cannot be deleted. Deactivate it instead.'], 422);
        } $terminal->delete();

        return response()->noContent();
    }

    public function chargeRules(Request $request)
    {
        return response()->json(['data' => $this->agent($request)->chargeRules()->with('provider')->orderByDesc('priority')->orderBy('minimum_amount')->get()]);
    }

    public function storeChargeRule(Request $request)
    {
        $validated = $this->validateChargeRule($request);
        $agent = $this->agent($request);
        $this->rejectEqualPriorityOverlap($agent->id, $validated);
        $rule = $agent->chargeRules()->create($validated);

        return response()->json($rule->load('provider'), 201);
    }

    public function updateChargeRule(Request $request, ChargeRule $chargeRule)
    {
        $agent = $this->agent($request);
        abort_unless($chargeRule->agent_profile_id === $agent->id, 404);
        $validated = $this->validateChargeRule($request, false);
        $candidate = array_merge($chargeRule->only(['minimum_amount', 'maximum_amount', 'provider_id', 'priority', 'charge_type']), $validated);
        $this->validateChargeRange($candidate);
        $this->rejectEqualPriorityOverlap($agent->id, $candidate, $chargeRule->id);
        $chargeRule->update($validated);

        return response()->json($chargeRule->fresh('provider'));
    }

    public function deleteChargeRule(Request $request, ChargeRule $chargeRule)
    {
        abort_unless($chargeRule->agent_profile_id === $this->agent($request)->id, 404);
        $chargeRule->delete();

        return response()->noContent();
    }

    public function previewCharge(Request $request, ChargeCalculationService $charges)
    {
        $validated = $request->validate(['amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'provider_id' => ['nullable', 'integer', 'exists:providers,id']]);
        $agent = $this->agent($request);
        $rule = $charges->ruleFor($agent, $validated['amount'], $validated['provider_id'] ?? null);

        return response()->json(['amount' => Money::add('0', $validated['amount']), 'charge' => $charges->calculate($agent, $validated['amount'], $validated['provider_id'] ?? null), 'rule' => $rule?->load('provider')]);
    }

    public function expenses(Request $request)
    {
        return response()->json(['data' => $this->agent($request)->expenses()->latest('expense_date')->paginate(min($request->integer('per_page', 25), 100))]);
    }

    public function storeExpense(Request $request)
    {
        $validated = $request->validate(['amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'category' => ['required', Rule::in(['transport', 'power', 'staff', 'cash handling', 'miscellaneous'])], 'description' => ['nullable', 'string', 'max:500'], 'expense_date' => ['required', 'date']]);

        return response()->json($this->agent($request)->expenses()->create($validated), 201);
    }

    public function updateExpense(Request $request, Expense $expense)
    {
        abort_unless($expense->agent_profile_id === $this->agent($request)->id, 404);
        $validated = $request->validate(['amount' => ['sometimes', 'regex:/^\d+(\.\d{1,2})?$/'], 'category' => ['sometimes', Rule::in(['transport', 'power', 'staff', 'cash handling', 'miscellaneous'])], 'description' => ['nullable', 'string', 'max:500'], 'expense_date' => ['sometimes', 'date']]);
        $expense->update($validated);

        return response()->json($expense->fresh());
    }

    public function deleteExpense(Request $request, Expense $expense)
    {
        abort_unless($expense->agent_profile_id === $this->agent($request)->id, 404);
        $expense->delete();

        return response()->noContent();
    }

    public function transaction(Request $request, Transaction $transaction, EarningsService $earnings)
    {
        $agent = $this->agent($request);
        abort_unless($transaction->agent_profile_id === $agent->id, 404);
        $transaction->load(['provider', 'terminal', 'importBatch', 'chargeOverrideBy', 'adjustments']);

        return response()->json([...$transaction->toArray(), 'reconciliation_status' => $transaction->settlement_status?->value, 'estimated_earnings' => $earnings->transactionContribution($transaction), 'financial_status' => $earnings->transactionFinancialStatus($transaction)]);
    }

    public function transactions(Request $request, EarningsService $earnings)
    {
        $filters = $request->validate(['provider_id' => ['nullable', 'integer'], 'terminal_id' => ['nullable', 'integer'], 'transaction_status' => ['nullable', Rule::in(['successful', 'pending', 'failed', 'reversed'])], 'settlement_status' => ['nullable', Rule::in(['pending', 'settled', 'unreconciled', 'disputed'])], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'reference' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = $this->agent($request)->transactions()->with(['provider', 'terminal', 'importBatch', 'adjustments'])->latest('transaction_at');
        foreach (['provider_id', 'terminal_id', 'transaction_status', 'settlement_status'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $value) => $q->where($field, $value));
        } $query->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('transaction_at', '>=', $value))->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('transaction_at', '<=', $value))->when($filters['reference'] ?? null, fn ($q, $value) => $q->where('external_reference', 'like', '%'.$value.'%'));
        $transactions = $query->paginate($filters['per_page'] ?? 25);
        $transactions->getCollection()->transform(fn ($transaction) => [...$transaction->toArray(), 'reconciliation_status' => $transaction->settlement_status?->value, 'estimated_earnings' => $earnings->transactionContribution($transaction), 'financial_status' => $earnings->transactionFinancialStatus($transaction)]);

        return response()->json($transactions);
    }

    public function importHistory(Request $request)
    {
        return response()->json(['data' => $this->agent($request)->importBatches()->with('provider')->latest()->paginate(min($request->integer('per_page', 25), 100))]);
    }

    public function importBatch(Request $request, ImportBatch $importBatch)
    {
        abort_unless($importBatch->agent_profile_id === $this->agent($request)->id, 404);

        return response()->json($importBatch->load('provider', 'transactions', 'settlements'));
    }

    private function agent(Request $request)
    {
        abort_unless($request->user()?->businessAgentProfile(), 404);

        return $request->user()->businessAgentProfile();
    }

    private function connectionState(ProviderConnection $connection): string
    {
        if ($connection->connection_status?->value === 'error') {
            return 'sync_error';
        }

        return match ($connection->connection_type?->value) {
            'csv' => 'csv_only', 'manual' => 'manual', default => data_get($connection->metadata, 'connected', false) ? 'connected' : 'demo'
        };
    }

    private function validateChargeRule(Request $request, bool $required = true): array
    {
        $rules = ['minimum_amount' => [$required ? 'required' : 'sometimes', 'regex:/^\d+(\.\d{1,2})?$/'], 'maximum_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'charge_type' => [$required ? 'required' : 'sometimes', Rule::enum(ChargeType::class)], 'charge_value' => [$required ? 'required' : 'sometimes', 'regex:/^\d+(\.\d{1,4})?$/'], 'provider_id' => ['nullable', 'integer', 'exists:providers,id'], 'priority' => ['sometimes', 'integer', 'min:0'], 'active' => ['sometimes', 'boolean']];
        $validated = $request->validate($rules);
        $this->validateChargeRange($validated);

        return $validated;
    }

    private function validateChargeRange(array $values): void
    {
        if (isset($values['maximum_amount'], $values['minimum_amount']) && $values['maximum_amount'] !== null && Money::compare($values['maximum_amount'], $values['minimum_amount']) < 0) {
            abort(response()->json(['message' => 'Maximum amount must be greater than or equal to minimum amount.'], 422));
        } if (($values['charge_type'] ?? null) === ChargeType::Percentage->value && isset($values['charge_value']) && Money::compare($values['charge_value'], '100') > 0) {
            abort(response()->json(['message' => 'Percentage charge cannot exceed 100.'], 422));
        }
    }

    private function rejectEqualPriorityOverlap(int $agentId, array $values, ?int $ignoreId = null): void
    {
        $rules = ChargeRule::where('agent_profile_id', $agentId)->where('active', true)->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))->get();
        foreach ($rules as $rule) {
            if (($values['priority'] ?? 0) !== $rule->priority || ($values['provider_id'] ?? null) !== $rule->provider_id) {
                continue;
            } $newMax = $values['maximum_amount'] ?? null;
            $overlap = ($newMax === null || Money::compare($rule->minimum_amount, $newMax) <= 0) && ($rule->maximum_amount === null || Money::compare($values['minimum_amount'], $rule->maximum_amount) <= 0);
            if ($overlap) {
                abort(response()->json(['message' => 'Active charge ranges cannot overlap at the same priority for the same provider. Increase priority or adjust the range.'], 422));
            }
        }
    }
}
