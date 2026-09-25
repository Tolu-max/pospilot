<?php

namespace App\Http\Controllers;

use App\Services\EarningsService;
use App\Services\ReconciliationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request, EarningsService $earnings, ReconciliationService $reconciliation)
    {
        $agent = $request->user()->agentProfile;
        abort_unless($agent, 404, 'Agent profile not found.');
        $transactions = $agent->transactions()->with('provider')->latest('transaction_at')->limit(5)->get();
        $settlements = $agent->settlements()->with('provider')->latest('settlement_date')->get()->map(fn ($settlement) => [...$reconciliation->compare($settlement), 'id' => $settlement->id, 'provider' => $settlement->provider->name, 'settlement_date' => $settlement->settlement_date->toDateString()]);

        return Inertia::render('Dashboard', ['businessName' => $agent->business_name, 'earnings' => $earnings->summarize($agent), 'transactions' => $transactions, 'settlements' => $settlements]);
    }
}
