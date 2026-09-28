<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Notifications\SecurityAlertNotification;
use App\Services\AccountSessionService;
use App\Services\SecurityEventRecorder;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(
        ProfileUpdateRequest $request,
        SecurityEventRecorder $events,
        TransactionalEmailDelivery $delivery,
    ): RedirectResponse {
        $user = $request->user();
        $previousEmail = $user->email;
        $emailChanged = $previousEmail !== $request->validated('email');
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $delivery->send(
                fn () => $user->sendEmailVerificationNotification(),
                'email_verification',
            );
            $delivery->send(
                fn () => Notification::route('mail', $previousEmail)->notify(new SecurityAlertNotification(
                    'Your POSPilot email address changed',
                    'The email address on your POSPilot account was changed. If you did not make this change, sign in and secure your account.',
                    'For your security, this email does not include account or transaction details.',
                )),
                'email_changed',
            );
            $events->record($user, 'email_changed', $request, ['method' => 'password']);
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(
        Request $request,
        AccountSessionService $sessions,
        SecurityEventRecorder $events,
    ): RedirectResponse {
        $user = $request->user();

        if ($user->getAuthPassword() !== null) {
            $request->validate(['password' => ['required', 'current_password']]);
        }

        $events->record($user, 'account_deleted', $request, ['method' => $user->google_id ? 'google' : 'password']);

        if (config('session.driver') === 'database') {
            $sessions->revokeAllSessions($user);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
