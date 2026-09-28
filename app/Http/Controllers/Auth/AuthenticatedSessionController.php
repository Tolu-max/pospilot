<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, SecurityEventRecorder $events): RedirectResponse
    {
        $request->authenticate($events);

        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
        $events->record($request->user(), 'login', $request, ['method' => 'password']);

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('analytics_event', ['name' => 'login_completed', 'id' => (string) Str::uuid()]);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, SecurityEventRecorder $events): RedirectResponse
    {
        $events->record($request->user(), 'logout', $request, ['method' => 'password']);
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
