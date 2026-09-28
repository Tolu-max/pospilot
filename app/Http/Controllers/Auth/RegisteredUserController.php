<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, SecurityEventRecorder $events, TransactionalEmailDelivery $delivery): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $delivery->send(fn () => event(new Registered($user)), 'email_verification');

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
        $events->record($user, 'account_registered', $request, ['method' => 'password']);
        $events->record($user, 'login', $request, ['method' => 'password']);

        return redirect(route('dashboard', absolute: false))
            ->with('analytics_event', ['name' => 'signup_completed', 'id' => (string) Str::uuid()]);
    }
}
