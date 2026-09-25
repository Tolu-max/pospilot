<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $request->session()->put('google_auth_intent', 'login');

        return $this->redirectToGoogle();
    }

    public function link(Request $request): RedirectResponse
    {
        $request->session()->put('google_auth_intent', 'link');
        $request->session()->put('google_link_user_id', $request->user()->id);

        return $this->redirectToGoogle();
    }

    public function reauthenticate(Request $request): RedirectResponse
    {
        $request->session()->put('google_auth_intent', 'reauthenticate');
        $request->session()->put('google_link_user_id', $request->user()->id);

        return $this->redirectToGoogle();
    }

    public function callback(Request $request, SecurityEventRecorder $events): RedirectResponse
    {
        $intent = $request->session()->pull('google_auth_intent', 'login');
        $linkedUserId = $request->session()->pull('google_link_user_id');

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->reject($request, $events, 'oauth_state_rejected', $intent);
        } catch (Throwable) {
            return $this->reject($request, $events, 'google_auth_failed', $intent);
        }

        $googleId = trim((string) $googleUser->getId());
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $emailVerified = filter_var(data_get($googleUser->user, 'verified_email'), FILTER_VALIDATE_BOOL);

        if ($googleId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $emailVerified) {
            return $this->reject($request, $events, 'google_identity_unverified', $intent);
        }

        if ($intent === 'link') {
            return $this->linkIdentity($request, $events, $googleId, $email, $linkedUserId);
        }

        if ($intent === 'reauthenticate') {
            return $this->reauthenticateIdentity($request, $events, $googleId, $email, $linkedUserId);
        }

        return $this->signInOrRegister($request, $events, $googleUser, $googleId, $email);
    }

    private function redirectToGoogle(): RedirectResponse
    {
        if (! filled(config('services.google.client_id'))
            || ! filled(config('services.google.client_secret'))
            || ! filled(config('services.google.redirect'))) {
            return redirect()->route('login')->with('status', 'Google sign-in is not configured.');
        }

        try {
            return Socialite::driver('google')->redirect();
        } catch (Throwable) {
            return redirect()->route('login')->with('status', 'Google sign-in could not be started. Please try again.');
        }
    }

    private function linkIdentity(
        Request $request,
        SecurityEventRecorder $events,
        string $googleId,
        string $email,
        mixed $linkedUserId,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user || (string) $user->id !== (string) $linkedUserId
            || Str::lower($user->email) !== $email
            || User::query()->where('google_id', $googleId)->where('id', '!=', $user->id)->exists()
            || ($user->google_id !== null && $user->google_id !== $googleId)) {
            return $this->reject($request, $events, 'google_link_rejected', 'link');
        }

        $user->forceFill([
            'google_id' => $googleId,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $request->session()->passwordConfirmed();
        $events->record($user, 'google_account_linked', $request, ['method' => 'google']);

        return redirect()->intended(route('profile.edit', absolute: false))
            ->with('status', 'Google account linked.');
    }

    private function reauthenticateIdentity(
        Request $request,
        SecurityEventRecorder $events,
        string $googleId,
        string $email,
        mixed $linkedUserId,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user || (string) $user->id !== (string) $linkedUserId
            || $user->google_id !== $googleId
            || Str::lower($user->email) !== $email) {
            return $this->reject($request, $events, 'google_reauthentication_rejected', 'reauthenticate');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
        $events->record($user, 'reauthentication', $request, ['method' => 'google']);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function signInOrRegister(
        Request $request,
        SecurityEventRecorder $events,
        object $googleUser,
        string $googleId,
        string $email,
    ): RedirectResponse {
        $user = User::query()->where('google_id', $googleId)->first();

        if (! $user && User::query()->where('email', $email)->exists()) {
            return $this->reject($request, $events, 'google_account_link_required', 'login');
        }

        $created = false;

        try {
            if (! $user) {
                $name = trim((string) $googleUser->getName());
                $user = User::query()->create([
                    'name' => $name !== '' ? $name : Str::before($email, '@'),
                    'email' => $email,
                    'password' => null,
                ]);
                $user->forceFill([
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                ])->save();
                $created = true;
            }
        } catch (QueryException) {
            return $this->reject($request, $events, 'google_account_conflict', 'login');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();

        if ($created) {
            $events->record($user, 'account_registered', $request, ['method' => 'google']);
        }

        $events->record($user, 'login', $request, ['method' => 'google']);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function reject(
        Request $request,
        SecurityEventRecorder $events,
        string $eventType,
        mixed $intent,
    ): RedirectResponse {
        $user = $request->user();
        $events->record($user, $eventType, $request, ['method' => 'google']);

        $route = $intent === 'link' ? 'profile.edit' : 'login';
        $message = $intent === 'link'
            ? 'Google account could not be linked. Please verify the Google account and try again.'
            : 'Google sign-in could not be completed. Please try again or use email and password.';

        return redirect()->route($route)->with('status', $message);
    }
}
