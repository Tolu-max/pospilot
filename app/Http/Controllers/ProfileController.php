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
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent !== null, 404);

        return response()->streamDownload(function () use ($agent): void {
            echo '{"business":'.json_encode($agent->only(['business_name', 'country', 'currency', 'location']), JSON_THROW_ON_ERROR);

            $writeRows = function (string $key, iterable $rows, callable $map): void {
                echo ','.json_encode($key, JSON_THROW_ON_ERROR).':[';
                $first = true;
                foreach ($rows as $row) {
                    if (! $first) {
                        echo ',';
                    }
                    echo json_encode($map($row), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    $first = false;
                }
                echo ']';
            };

            $writeRows('transactions', $agent->transactions()->with(['provider:id,name', 'terminal:id,name'])->orderBy('id')->lazyById(250), fn ($transaction): array => [
                'provider' => $transaction->provider?->name,
                'terminal' => $transaction->terminal?->name,
                'transaction_type' => $transaction->transaction_type,
                'amount' => $transaction->amount,
                'customer_charge' => $transaction->customer_charge,
                'provider_fee' => $transaction->provider_fee_supplied ? $transaction->provider_fee : null,
                'provider_fee_known' => (bool) $transaction->provider_fee_supplied,
                'status' => $transaction->transaction_status?->value ?? $transaction->transaction_status,
                'occurred_at' => $transaction->transaction_at?->toIso8601String(),
                'source' => $transaction->source?->value ?? $transaction->source,
            ]);
            $writeRows('expenses', $agent->expenses()->with('terminal:id,name')->orderBy('id')->lazyById(250), fn ($expense): array => [
                'terminal' => $expense->terminal?->name,
                'category' => $expense->category,
                'amount' => $expense->amount,
                'date' => $expense->expense_date?->toDateString(),
            ]);
            $writeRows('settlements', $agent->settlements()->with(['provider:id,name', 'terminal:id,name'])->orderBy('id')->lazyById(250), fn ($settlement): array => [
                'provider' => $settlement->provider?->name,
                'terminal' => $settlement->terminal?->name,
                'settlement_reference' => $settlement->settlement_reference ? '••••'.substr($settlement->settlement_reference, -4) : null,
                'expected_amount' => $settlement->expected_amount,
                'actual_amount' => $settlement->actual_amount,
                'provider_fee' => $settlement->provider_fee_supplied ? $settlement->provider_fee : null,
                'provider_fee_known' => (bool) $settlement->provider_fee_supplied,
                'date' => $settlement->settlement_date?->toDateString(),
                'status' => $settlement->status?->value ?? $settlement->status,
            ]);
            echo '}';
        }, 'pospilot-business-data-'.now()->toDateString().'.json', ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'canManageBusiness' => $request->user()->businessRole() === 'owner',
            'hasPassword' => filled($request->user()->getAuthPassword()),
            'googleReauthenticationUrl' => $request->user()->google_id ? route('auth.google.reauthenticate') : null,
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
