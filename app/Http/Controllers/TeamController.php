<?php

namespace App\Http\Controllers;

use App\Models\BusinessInvitation;
use App\Models\BusinessMembership;
use App\Models\BusinessShiftIssue;
use App\Models\Terminal;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Notifications\TeamInvitationNotification;
use App\Services\SecurityEventRecorder;
use App\Services\ShiftCashSummaryService;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Team/Index', [
            'businessName' => $request->user()->businessAgentProfile()->business_name,
        ]);
    }

    public function members(Request $request): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        $members = $agent->memberships()->with(['user:id,name,email', 'terminals:id,name,provider_id'])
            ->latest()->get()->map(fn (BusinessMembership $member): array => [
                'id' => $member->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'role' => $member->role,
                'is_active' => $member->is_active,
                'terminals' => $member->terminals->map(fn (Terminal $terminal): array => ['id' => $terminal->id, 'name' => $terminal->name])->values(),
            ]);
        $invitations = $agent->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->latest()->get(['id', 'email', 'role', 'expires_at']);

        return response()->json([
            'members' => $members,
            'invitations' => $invitations,
            'terminals' => $agent->terminals()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function invite(Request $request, SecurityEventRecorder $events): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'lowercase', 'max:255'],
            'role' => ['required', Rule::in(['manager', 'attendant'])],
        ]);
        $agent = $request->user()->businessAgentProfile();
        $alreadyMember = $agent->memberships()->whereHas('user', fn ($query) => $query->where('email', $validated['email']))->exists();
        if ($alreadyMember) {
            return response()->json(['message' => 'This person is already on your team.'], 422);
        }
        $existingUser = User::where('email', $validated['email'])->first();
        if ($existingUser !== null && ($existingUser->agentProfile()->exists() || $existingUser->teamMembership()->exists())) {
            return response()->json(['message' => 'This account is already linked to a business workspace.'], 422);
        }

        $agent->invitations()->where('email', $validated['email'])->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $token = Str::random(64);
        $invitation = $agent->invitations()->create([
            'invited_by' => $request->user()->id,
            'email' => $validated['email'],
            'role' => $validated['role'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);
        $url = route('team.invitations.show', ['token' => $token]);
        try {
            Notification::route('mail', $invitation->email)->notify(new TeamInvitationNotification($agent->business_name, $request->user()->name, $url));
        } catch (Throwable) {
            $invitation->update(['revoked_at' => now()]);

            return response()->json(['message' => 'We could not send the invitation. Check the mail setup and try again.'], 503);
        }

        $events->record($request->user(), 'staff_invited', $request, ['role' => $invitation->role]);

        return response()->json(['id' => $invitation->id, 'email' => $invitation->email, 'role' => $invitation->role, 'expires_at' => $invitation->expires_at], 201);
    }

    public function revokeInvitation(Request $request, BusinessInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->agent_profile_id === $request->user()->businessAgentProfile()?->id, 404);
        abort_if($invitation->accepted_at !== null, 422, 'This invitation has already been accepted.');
        $invitation->update(['revoked_at' => now()]);

        return back()->with('success', 'Invitation cancelled.');
    }

    public function updateMember(Request $request, BusinessMembership $member, SecurityEventRecorder $events, TransactionalEmailDelivery $delivery): JsonResponse
    {
        abort_unless($member->agent_profile_id === $request->user()->businessAgentProfile()?->id, 404);
        $validated = $request->validate([
            'role' => ['sometimes', Rule::in(['manager', 'attendant'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $hasActiveShift = $member->shifts()->where('status', 'active')->exists();
        if ($hasActiveShift && (($validated['is_active'] ?? true) === false || (($validated['role'] ?? $member->role) !== 'attendant'))) {
            return response()->json(['message' => 'This team member must end their active shift before their access changes.'], 422);
        }
        $previousRole = $member->role;
        $wasActive = $member->is_active;
        $member->update($validated);
        if ($previousRole !== $member->role) {
            $events->record($request->user(), 'staff_role_changed', $request, ['role' => $member->role]);
        } elseif ($wasActive !== $member->is_active) {
            $events->record($request->user(), $member->is_active ? 'staff_access_restored' : 'staff_removed', $request, ['role' => $member->role, 'active' => $member->is_active]);
        }

        if ($previousRole !== $member->role || $wasActive !== $member->is_active) {
            $headline = $member->is_active ? 'Your POSPilot team access changed' : 'Your POSPilot team access was removed';
            $intro = $member->is_active
                ? 'The role or terminal access on your POSPilot team account was updated. Contact your business owner if you were not expecting this change.'
                : 'Your access to a POSPilot business workspace was removed. Contact the business owner if you were not expecting this change.';
            $delivery->send(
                fn () => Notification::route('mail', $member->user->email)->notify(new SecurityAlertNotification(
                    $headline,
                    $intro,
                    'This notification contains no transaction details or provider credentials.',
                )),
                'team_access_changed',
            );
        }

        return response()->json(['id' => $member->id, 'role' => $member->role, 'is_active' => $member->is_active]);
    }

    public function assignTerminals(Request $request, BusinessMembership $member, SecurityEventRecorder $events, TransactionalEmailDelivery $delivery): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($member->agent_profile_id === $agent->id, 404);
        $validated = $request->validate([
            'terminal_ids' => ['present', 'array', 'max:100'],
            'terminal_ids.*' => ['integer', 'distinct', Rule::exists('terminals', 'id')->where('agent_profile_id', $agent->id)],
        ]);
        if ($member->shifts()->where('status', 'active')->whereNotIn('terminal_id', $validated['terminal_ids'])->exists()) {
            return response()->json(['message' => 'An assigned terminal is in use. Ask the attendant to end their shift before changing assignments.'], 422);
        }
        $previousTerminalIds = $member->terminals()->pluck('terminals.id')->sort()->values()->all();
        $terminalIds = collect($validated['terminal_ids'])->sort()->values()->all();
        $member->terminals()->sync($terminalIds);
        $events->record($request->user(), 'terminal_assignment_updated', $request, ['terminal_count' => count($validated['terminal_ids'])]);

        if ($previousTerminalIds !== $terminalIds) {
            $delivery->send(
                fn () => Notification::route('mail', $member->user->email)->notify(new SecurityAlertNotification(
                    'Your POSPilot terminal assignments changed',
                    'The terminals assigned to your POSPilot team account were updated. Contact your business owner if you were not expecting this change.',
                    'This notification does not include transaction details or provider credentials.',
                )),
                'terminal_assignment_changed',
            );
        }

        return response()->json(['terminal_ids' => array_values($member->terminals()->pluck('terminals.id')->all())]);
    }

    public function activity(Request $request, ShiftCashSummaryService $cashSummary): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        $shifts = $agent->shifts()->with(['membership.user:id,name', 'terminal:id,name', 'issues:id,business_shift_id,subject,status'])
            ->latest('started_at')->limit(30)->get()->map(fn ($shift): array => [
                'id' => $shift->id,
                'staff' => $shift->membership->user->name,
                'terminal' => $shift->terminal->name,
                'started_at' => $shift->started_at,
                'ended_at' => $shift->ended_at,
                'status' => $shift->status,
                'opening_cash' => $shift->opening_cash,
                'closing_cash' => $shift->closing_cash,
                'closing_notes' => $shift->closing_notes,
                'cash_summary' => $cashSummary->forShift($shift),
                'issues' => $shift->issues->map(fn ($issue): array => ['id' => $issue->id, 'subject' => $issue->subject, 'status' => $issue->status]),
            ]);

        return response()->json(['data' => $shifts]);
    }

    public function resolveIssue(Request $request, BusinessShiftIssue $issue, SecurityEventRecorder $events): JsonResponse
    {
        abort_unless($issue->shift()->where('agent_profile_id', $request->user()->businessAgentProfile()?->id)->exists(), 404);
        $issue->update(['status' => 'resolved', 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        $events->record($request->user(), 'shift_issue_resolved', $request);

        return response()->json(['id' => $issue->id, 'status' => $issue->status]);
    }

    public function securityActivity(Request $request): JsonResponse
    {
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent !== null, 404);

        return response()->json(['data' => $agent->securityEvents()
            ->with('user:id,name')
            ->latest('occurred_at')
            ->limit(50)
            ->get(['id', 'user_id', 'event_type', 'metadata', 'occurred_at'])
            ->map(fn ($event): array => [
                'id' => $event->id,
                'actor' => $event->user?->name ?? 'Former team member',
                'event' => $event->event_type,
                'details' => $event->metadata ?? [],
                'occurred_at' => $event->occurred_at,
            ])
            ->values()]);
    }
}
