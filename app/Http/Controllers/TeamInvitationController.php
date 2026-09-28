<?php

namespace App\Http\Controllers;

use App\Models\BusinessInvitation;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

final class TeamInvitationController extends Controller
{
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->findValidInvitation($token);
        abort_unless($invitation, 404);
        if ($request->user() !== null && $request->user()->email !== $invitation->email) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return Inertia::render('Team/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'businessName' => $invitation->agentProfile->business_name,
            'authenticated' => $request->user()?->email === $invitation->email,
            'accountExists' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findValidInvitation($token);
        abort_unless($invitation, 404);
        $user = $request->user();
        $validated = null;

        if ($user === null) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ]);
            abort_if(User::where('email', $invitation->email)->exists(), 409, 'Sign in to accept this invitation.');
        } else {
            abort_unless(mb_strtolower($user->email) === mb_strtolower($invitation->email), 403);
        }

        $user = DB::transaction(function () use ($invitation, $user, $validated): User {
            $lockedInvitation = BusinessInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedInvitation->accepted_at !== null || $lockedInvitation->revoked_at !== null || $lockedInvitation->expires_at->isPast(), 410);
            if ($user === null) {
                abort_if(User::where('email', $lockedInvitation->email)->exists(), 409, 'Sign in to accept this invitation.');
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $lockedInvitation->email,
                    'password' => Hash::make($validated['password']),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            } else {
                abort_if($user->agentProfile()->exists() || $user->teamMembership()->exists(), 409, 'This account already belongs to a workspace.');
                if (! $user->hasVerifiedEmail()) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
            }
            abort_if(BusinessMembership::where('user_id', $user->id)->exists(), 409, 'This account already belongs to a workspace.');
            BusinessMembership::create([
                'agent_profile_id' => $lockedInvitation->agent_profile_id,
                'user_id' => $user->id,
                'invited_by' => $lockedInvitation->invited_by,
                'role' => $lockedInvitation->role,
                'is_active' => true,
            ]);
            $lockedInvitation->update(['accepted_at' => now()]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();

        return redirect()->route('dashboard')->with('success', 'You joined '.$invitation->agentProfile->business_name.'.');
    }

    private function findValidInvitation(string $token): ?BusinessInvitation
    {
        return BusinessInvitation::with('agentProfile')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
