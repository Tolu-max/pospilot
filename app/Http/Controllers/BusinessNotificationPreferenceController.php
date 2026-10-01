<?php

namespace App\Http\Controllers;

use App\Models\BusinessNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessNotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent !== null, 404);
        $preference = BusinessNotificationPreference::firstOrCreate(['agent_profile_id' => $agent->id]);

        return response()->json($this->safePreferences($preference));
    }

    public function update(Request $request): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent !== null, 404);
        $validated = $request->validate([
            'closing_reminder_enabled' => ['required', 'boolean'],
            'daily_summary_enabled' => ['required', 'boolean'],
            'issue_reminder_enabled' => ['required', 'boolean'],
        ]);
        $preference = BusinessNotificationPreference::updateOrCreate(['agent_profile_id' => $agent->id], $validated);

        return response()->json($this->safePreferences($preference));
    }

    /** @return array{closing_reminder_enabled:bool,daily_summary_enabled:bool,issue_reminder_enabled:bool} */
    private function safePreferences(BusinessNotificationPreference $preference): array
    {
        return [
            'closing_reminder_enabled' => (bool) $preference->closing_reminder_enabled,
            'daily_summary_enabled' => (bool) $preference->daily_summary_enabled,
            'issue_reminder_enabled' => (bool) $preference->issue_reminder_enabled,
        ];
    }
}
