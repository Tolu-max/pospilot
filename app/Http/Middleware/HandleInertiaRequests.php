<?php

namespace App\Http\Middleware;

use App\Enums\OnboardingState;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'workspace' => [
                'role' => $request->user()?->businessRole(),
                'business_name' => $request->user()?->businessAgentProfile()?->business_name,
            ],
            'workspaceReady' => $request->user()?->businessAgentProfile()?->onboarding_state === OnboardingState::Completed,
            'analyticsEvent' => fn (): ?array => $request->session()->get('analytics_event'),
            'features' => [
                'moniepointDirect' => (bool) config('provider_secrets.moniepoint_direct_enabled'),
                'opayDirect' => (bool) config('provider_secrets.opay_direct_enabled'),
                'palmpayDirect' => (bool) config('provider_secrets.palmpay_direct_enabled'),
                'gmailStatements' => (bool) config('gmail_statement.enabled'),
            ],
        ];
    }
}
