<?php

namespace App\Http\Controllers;

use App\Services\BusinessInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessInsightController extends Controller
{
    public function __invoke(Request $request, BusinessInsightService $insights): JsonResponse
    {
        if (! config('services.cencori.enabled')) {
            return response()->json([
                'message' => 'Business Insight is coming soon.',
            ], 503);
        }

        $agent = $request->user()?->agentProfile;
        abort_unless($agent !== null, 404);

        $explanation = $insights->explainToday($agent);
        if ($explanation === null) {
            return response()->json([
                'message' => 'Business Insight is temporarily unavailable. Your dashboard figures are still up to date.',
            ], 503);
        }

        return response()->json(['explanation' => $explanation]);
    }
}
