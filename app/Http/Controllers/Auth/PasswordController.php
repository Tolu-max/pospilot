<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\SecurityAlertNotification;
use App\Services\AccountSessionService;
use App\Services\SecurityEventRecorder;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(
        Request $request,
        AccountSessionService $sessions,
        SecurityEventRecorder $events,
        TransactionalEmailDelivery $delivery,
    ): RedirectResponse {
        $hasExistingPassword = filled($request->user()->getAuthPassword());
        $validated = $request->validate([
            'current_password' => $hasExistingPassword ? ['required', 'current_password'] : ['nullable'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        if ($hasExistingPassword) {
            Auth::guard('web')->logoutOtherDevices($validated['current_password']);
        }

        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ]);

        if (config('session.driver') === 'database') {
            $sessions->revokeOtherSessions($request->user(), $request->session()->getId());
        }

        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
        $events->record($request->user(), 'password_changed', $request, ['method' => 'password']);
        $delivery->send(
            fn () => $request->user()->notify(new SecurityAlertNotification(
                'Your password was changed',
                'The password for your POSPilot account was changed. If you did not make this change, sign in and secure your account.',
                'For your security, this email does not include account or transaction details.',
            )),
            'password_changed',
        );

        return back();
    }
}
