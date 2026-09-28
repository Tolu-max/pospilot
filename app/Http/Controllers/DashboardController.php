<?php

namespace App\Http\Controllers;

use App\Enums\OnboardingState;
use App\Services\EarningsService;
use App\Services\ReconciliationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request, EarningsService $earnings, ReconciliationService $reconciliation)
    {
        $agent = $request->user()->businessAgentProfile();
        if ($agent === null || $agent->onboarding_state !== OnboardingState::Completed) {
            return Inertia::render('Onboarding/Start', [
                'userName' => $request->user()->name,
                'profile' => $agent?->only(['business_name', 'phone', 'country', 'currency', 'location', 'onboarding_state', 'selected_provider_slugs']),
            ]);
        }

        if ($request->user()->businessRole() !== 'owner') {
            return Inertia::render('Dashboard', [
                'businessName' => $agent->business_name,
                'staffRole' => $request->user()->businessRole(),
            ]);
        }

        $transactions = $agent->transactions()->with('provider')->latest('transaction_at')->limit(5)->get();
        $settlements = $agent->settlements()->with('provider')->latest('settlement_date')->get()->map(fn ($settlement) => [...$reconciliation->compare($settlement), 'id' => $settlement->id, 'provider' => $settlement->provider->name, 'settlement_date' => $settlement->settlement_date->toDateString()]);

        return Inertia::render('Dashboard', ['businessName' => $agent->business_name, 'earnings' => $earnings->summarize($agent), 'transactions' => $transactions, 'settlements' => $settlements, 'businessInsightEnabled' => (bool) config('services.cencori.enabled')]);
    }
}
