<?php

namespace App\Http\Controllers;

use App\Models\DailyClosing;
use App\Services\DailyClosingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class DailyClosingController extends Controller
{
    public function preview(Request $request, DailyClosingService $service)
    {
        $date = $this->date($request);

        return response()->json($service->preview($this->agent($request), $date));
    }

    public function store(Request $request, DailyClosingService $service)
    {
        $validated = $request->validate($this->closingRules());
        $date = isset($validated['closing_date']) ? CarbonImmutable::parse($validated['closing_date']) : CarbonImmutable::today();
        try {
            return response()->json($service->saveDraft($this->agent($request), $date, $validated), 201);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function balance(Request $request, DailyClosing $dailyClosing, DailyClosingService $service)
    {
        $agent = $this->agent($request);
        abort_unless($dailyClosing->agent_profile_id === $agent->id, 404);
        $validated = $request->validate(['provider_id' => ['required', 'integer', 'exists:providers,id'], 'terminal_id' => ['nullable', 'integer'], 'actual_balance' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'metadata' => ['nullable', 'array']]);
        try {
            return response()->json($service->saveProviderBalance($agent, $dailyClosing, $validated));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function finalize(Request $request, DailyClosing $dailyClosing, DailyClosingService $service)
    {
        $agent = $this->agent($request);
        abort_unless($dailyClosing->agent_profile_id === $agent->id, 404);
        try {
            return response()->json($service->finalize($agent, $dailyClosing, $request->user()->id));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function index(Request $request)
    {
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = $this->agent($request)->dailyClosings()->latest('closing_date')->latest('id');
        $query->when($validated['from'] ?? null, fn ($q, $value) => $q->whereDate('closing_date', '>=', $value))->when($validated['to'] ?? null, fn ($q, $value) => $q->whereDate('closing_date', '<=', $value));

        return response()->json($query->paginate($validated['per_page'] ?? 25));
    }

    public function show(Request $request, DailyClosing $dailyClosing, DailyClosingService $service)
    {
        $agent = $this->agent($request);
        abort_unless($dailyClosing->agent_profile_id === $agent->id, 404);

        return response()->json($service->preview($agent, $dailyClosing->closing_date->toImmutable()));
    }

    public function breakdown(Request $request, DailyClosing $dailyClosing, DailyClosingService $service)
    {
        $agent = $this->agent($request);
        abort_unless($dailyClosing->agent_profile_id === $agent->id, 404);
        $result = $service->preview($agent, $dailyClosing->closing_date->toImmutable());

        return response()->json(['reasons' => $result['reasons'], 'pending_transaction_count' => $result['pending_transaction_count'], 'reversed_transaction_count' => $result['reversed_transaction_count'], 'total_variance' => $result['total_variance'], 'variance_status' => $result['variance_status']]);
    }

    private function agent(Request $request)
    {
        abort_unless($request->user()?->agentProfile, 404);

        return $request->user()->agentProfile;
    }

    private function date(Request $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->validate(['closing_date' => ['nullable', 'date']])['closing_date'] ?? today()->toDateString());
    }

    private function closingRules(): array
    {
        return ['closing_date' => ['nullable', 'date'], 'opening_cash' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'entered_closing_cash' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
