<?php

namespace App\Http\Middleware;

use App\Enums\OnboardingState;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    /**
     * Routes required to finish setting up a workspace.
     *
     * @var array<int, string>
     */
    private const SETUP_ROUTES = [
        'api.providers',
        'api.terminals.store',
        'api.charge-rules.index',
        'api.charge-rules.store',
        'api.charge-rules.update',
        'api.charge-rules.destroy',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || $request->routeIs(...self::SETUP_ROUTES)
            || $user->businessAgentProfile()?->onboarding_state === OnboardingState::Completed) {
            return $next($request);
        }

        $message = 'Complete your business setup before opening this page.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect_to' => route('dashboard'),
            ], 403);
        }

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => $message,
        ]);

        return redirect()->route('dashboard');
    }
}
