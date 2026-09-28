<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, TransactionalEmailDelivery $delivery): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $delivery->send(
            fn () => Password::sendResetLink($request->only('email')),
            'password_reset',
        );

        return back()->with('status', 'If an account with that email exists, a password reset link has been sent.');
    }
}
