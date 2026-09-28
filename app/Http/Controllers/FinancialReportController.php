<?php

namespace App\Http\Controllers;

use App\Models\Settlement;
use App\Services\AgentFinancialSummaryService;
use App\Services\EarningsService;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialReportController extends Controller
{
    public function __construct(private readonly AgentFinancialSummaryService $summary, private readonly EarningsService $earnings, private readonly ReconciliationService $reconciliation) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->summary->summarize($this->agent($request), ...$this->dates($request)));
    }

    public function providerBreakdown(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->earnings->byProvider($this->agent($request), ...$this->dates($request))]);
    }

    public function reconciliationOverview(Request $request): JsonResponse
    {
        return response()->json($this->reconciliation->overview($this->agent($request), ...$this->dates($request)));
    }

    public function reconciliationIssues(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reconciliation->issuesForAgent($this->agent($request), ...$this->dates($request))]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $items = $agent->settlements()->with(['provider', 'terminal'])->latest('settlement_date')->paginate(25);

        return response()->json($items);
    }

    public function settlement(Request $request, Settlement $settlement): JsonResponse
    {
        abort_unless($settlement->agent_profile_id === $this->agent($request)->id, 404);

        return response()->json(['settlement' => $settlement->load(['provider', 'terminal', 'importBatch']), 'reconciliation' => $this->reconciliation->compare($settlement)]);
    }

    private function agent(Request $request)
    {
        abort_unless($request->user()?->agentProfile, 404);

        return $request->user()->businessAgentProfile();
    }

    private function dates(Request $request): array
    {
        $from = $request->date('from') ? CarbonImmutable::parse($request->date('from')) : null;
        $to = $request->date('to') ? CarbonImmutable::parse($request->date('to')) : null;

        return [$from, $to];
    }
}
