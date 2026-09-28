<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request, TransactionalEmailDelivery $delivery): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $sent = $delivery->send(
            fn () => $request->user()->sendEmailVerificationNotification(),
            'email_verification',
        );

        return back()->with('status', $sent ? 'verification-link-sent' : 'verification-link-failed');
    }
}
