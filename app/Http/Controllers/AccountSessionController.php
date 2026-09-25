<?php

namespace App\Http\Controllers;

use App\Services\AccountSessionService;
use App\Services\SecurityEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AccountSessionController extends Controller
{
    public function index(Request $request, AccountSessionService $sessions): JsonResponse
    {
        $result = $sessions->activeSessions($request->user(), $request->session()->getId());

        return response()->json($result, $result['available'] ? 200 : 503);
    }

    public function destroyOthers(
        Request $request,
        AccountSessionService $sessions,
        SecurityEventRecorder $events,
    ): JsonResponse {
        try {
            $revoked = $sessions->revokeOtherSessions($request->user(), $request->session()->getId());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Session revocation requires the database session driver.'], 503);
        }

        $events->record($request->user(), 'sessions_revoked', $request, ['revoked_sessions' => $revoked]);

        return response()->json(['revoked_sessions' => $revoked]);
    }
}
