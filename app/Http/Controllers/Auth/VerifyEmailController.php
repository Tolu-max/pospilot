<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'Your email is already verified.',
            ]);

            return redirect()->route('dashboard');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Email verified. You can now finish setting up your business.',
        ]);

        return redirect()->route('dashboard');
    }
}
