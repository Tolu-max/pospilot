<?php

namespace App\Http\Controllers;

use App\Enums\OnboardingState;
use App\Enums\TransactionStatus;
use App\Models\BusinessShift;
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
        $actionItems = [];
        $openShiftCount = BusinessShift::query()->where('agent_profile_id', $agent->id)->where('status', 'active')->count();
        if ($openShiftCount > 0) {
            $actionItems[] = ['type' => 'open_shifts', 'label' => $openShiftCount.' staff shift'.($openShiftCount === 1 ? '' : 's').' still open', 'href' => '/dashboard?screen=team'];
        }
        $missingFeeCount = $agent->transactions()->posFinancial()->where('transaction_status', TransactionStatus::Successful->value)->where('provider_fee_supplied', false)->whereDate('transaction_at', '>=', today()->subDays(6))->count();
        if ($missingFeeCount > 0) {
            $actionItems[] = ['type' => 'missing_fees', 'label' => $missingFeeCount.' transaction'.($missingFeeCount === 1 ? ' is' : 's are').' missing provider-fee data; earnings remain provisional.', 'href' => '/transactions'];
        }
        $varianceClosing = $agent->dailyClosings()->where('status', 'finalized')->where('total_variance', '!=', '0.00')->latest('closing_date')->first();
        if ($varianceClosing !== null) {
            $actionItems[] = ['type' => 'closing_variance', 'label' => 'A daily closing has an unresolved recorded variance.', 'href' => '/dashboard?screen=closing'];
        }

        return Inertia::render('Dashboard', ['businessName' => $agent->business_name, 'earnings' => $earnings->summarize($agent), 'transactions' => $transactions, 'settlements' => $settlements, 'businessInsightEnabled' => (bool) config('services.cencori.enabled'), 'actionItems' => $actionItems]);
    }
}
