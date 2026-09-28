<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\SecurityAlertNotification;
use App\Services\AccountSessionService;
use App\Services\SecurityEventRecorder;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(
        Request $request,
        AccountSessionService $sessions,
        SecurityEventRecorder $events,
        TransactionalEmailDelivery $delivery,
    ): RedirectResponse {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request, $sessions, $events, $delivery): void {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                if (config('session.driver') === 'database') {
                    $sessions->revokeAllSessions($user);
                }

                event(new PasswordReset($user));
                $events->record($user, 'password_changed', $request, ['method' => 'password_reset']);
                $delivery->send(
                    fn () => $user->notify(new SecurityAlertNotification(
                        'Your password was reset',
                        'A password reset was completed for your POSPilot account. If you did not make this change, sign in and secure your account.',
                        'For your security, this email does not include account or transaction details.',
                    )),
                    'password_reset_completed',
                );
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => ['Unable to reset the password using those details.'],
        ]);
    }
}
