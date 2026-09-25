<?php

namespace App\Http\Controllers;

use App\Services\ReconciliationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReconciliationController extends Controller
{
    public function index(Request $request, ReconciliationService $reconciliation)
    {
        $agent = $request->user()->agentProfile;
        abort_unless($agent, 404, 'Agent profile not found.');
        $settlements = $agent->settlements()->with('provider')->latest('settlement_date')->get()->map(fn ($settlement) => [...$reconciliation->compare($settlement), 'id' => $settlement->id, 'provider' => $settlement->provider->name, 'settlement_date' => $settlement->settlement_date->toDateString(), 'reference' => $settlement->settlement_reference]);

        return Inertia::render('Reconciliation/Index', ['settlements' => $settlements]);
    }
}
