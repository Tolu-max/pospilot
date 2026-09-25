<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AccountSessionService;
use App\Services\SecurityEventRecorder;
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
    public function update(Request $request, AccountSessionService $sessions, SecurityEventRecorder $events): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        Auth::guard('web')->logoutOtherDevices($validated['current_password']);

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

        return back();
    }
}
