<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\BusinessInvitation;
use App\Models\BusinessMembership;
use App\Models\BusinessShift;
use App\Models\Provider;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamStaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_team_members_and_invitation_acceptance_creates_a_business_member(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'attendant@example.test', 'role' => 'attendant'])
            ->assertCreated()->assertJsonPath('email', 'attendant@example.test');
        $invitation = BusinessInvitation::firstOrFail();
        $token = 'a-secure-invitation-token-for-tests';
        $invitation->update(['token_hash' => hash('sha256', $token)]);
        Auth::logout();

        $this->post('/team/invitations/'.$token.'/accept', [
            'name' => 'Test Attendant',
            'password' => 'test-password-123',
            'password_confirmation' => 'test-password-123',
        ])->assertRedirect('/dashboard');

        $member = User::where('email', 'attendant@example.test')->firstOrFail();
        $this->assertDatabaseHas('business_memberships', [
            'agent_profile_id' => $agent->id,
            'user_id' => $member->id,
            'role' => 'attendant',
            'is_active' => true,
        ]);
        $this->assertNotNull($member->email_verified_at);
        $this->assertSame('attendant', $member->businessRole());
    }

    public function test_attendant_only_sees_transactions_for_the_assigned_terminal_during_their_shift(): void
    {
        [$owner, $agent, $member, $terminal, $provider] = $this->createTeam('attendant');
        $otherTerminal = Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'name' => 'Other counter']);
        $this->actingAs($owner)->putJson('/api/team/members/'.$member->teamMembership->id.'/terminals', ['terminal_ids' => [$terminal->id]])->assertOk();

        $this->actingAs($member)->getJson('/api/staff/dashboard')->assertOk()->assertJsonCount(0, 'transactions');
        $shift = $this->actingAs($member)->postJson('/api/staff/shifts', ['terminal_id' => $terminal->id, 'opening_cash' => '5000'])->assertCreated()->json();
        $this->actingAs($owner)->patchJson('/api/team/members/'.$member->teamMembership->id, ['is_active' => false])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/api/team/members/'.$member->teamMembership->id.'/terminals', ['terminal_ids' => []])->assertUnprocessable();
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'terminal_id' => $terminal->id, 'transaction_at' => now()]);
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'terminal_id' => $otherTerminal->id, 'transaction_at' => now()]);

        $this->actingAs($member)->getJson('/api/staff/shifts/'.$shift['id'].'/transactions')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.terminal', $terminal->name);
        $this->actingAs($member)->getJson('/api/transactions')->assertForbidden();
        $this->actingAs($member)->get('/integrations/gmail/connect')->assertForbidden();
        $this->actingAs($member)->getJson('/api/terminals')->assertForbidden();
        $this->actingAs($member)->getJson('/api/financial-summary')->assertForbidden();
        $this->assertSame($agent->id, $member->businessAgentProfile()->id);
    }

    public function test_attendant_can_record_cash_expenses_and_issues_then_submit_closing_cash(): void
    {
        [, $agent, $member, $terminal] = $this->createTeam('attendant');
        $membership = $member->teamMembership;
        $membership->terminals()->attach($terminal->id);
        $this->actingAs($member)->postJson('/api/staff/shifts', ['terminal_id' => $terminal->id])->assertCreated()->assertJsonPath('status', 'active');
        $shift = BusinessShift::firstOrFail();

        $this->postJson('/api/staff/shifts/'.$shift->id.'/cash-activity', [
            'entry_type' => 'cash_out', 'amount' => '300', 'category' => 'cash_payout', 'description' => 'Customer payout',
        ])->assertCreated();
        $this->postJson('/api/staff/shifts/'.$shift->id.'/expenses', [
            'amount' => '200', 'category' => 'transport', 'description' => 'Courier run',
        ])->assertCreated();
        $this->postJson('/api/staff/shifts/'.$shift->id.'/issues', [
            'subject' => 'Terminal receipt issue', 'description' => 'Receipt printer stopped during the shift.',
        ])->assertCreated();

        $this->postJson('/api/staff/shifts/'.$shift->id.'/close', ['closing_cash' => '4500'])->assertOk()->assertJsonPath('status', 'closed');
        $this->assertDatabaseHas('expenses', ['agent_profile_id' => $agent->id, 'business_membership_id' => $membership->id, 'business_shift_id' => $shift->id, 'amount' => '200.00']);
        $this->assertDatabaseHas('business_shift_cash_entries', ['business_shift_id' => $shift->id, 'entry_type' => 'expense', 'amount' => '200.00']);
        $this->assertDatabaseHas('business_shift_issues', ['business_shift_id' => $shift->id, 'status' => 'open']);
    }

    public function test_inactive_staff_and_other_businesses_cannot_use_or_assign_resources(): void
    {
        [$owner, $agent, $member, $terminal] = $this->createTeam('attendant');
        [$otherOwner, $otherAgent, $otherMember] = $this->createTeam('attendant');
        $otherTerminal = Terminal::factory()->create(['agent_profile_id' => $otherAgent->id]);

        $this->actingAs($owner)->putJson('/api/team/members/'.$member->teamMembership->id.'/terminals', ['terminal_ids' => [$otherTerminal->id]])->assertUnprocessable();
        $this->actingAs($otherOwner)->putJson('/api/team/members/'.$member->teamMembership->id.'/terminals', ['terminal_ids' => [$terminal->id]])->assertNotFound();
        $this->actingAs($otherMember)->getJson('/api/staff/shifts/999/transactions')->assertNotFound();

        $member->teamMembership->update(['is_active' => false]);
        $this->actingAs($member)->get('/dashboard')->assertForbidden();
        $this->actingAs($member)->getJson('/api/staff/dashboard')->assertForbidden();
    }

    public function test_manager_can_review_operations_but_not_change_team_or_connect_gmail(): void
    {
        [$owner, $agent, $manager] = $this->createTeam('manager');
        $this->actingAs($manager)->get('/transactions')->assertOk();
        $this->actingAs($manager)->get('/reconciliation')->assertOk();
        $this->actingAs($manager)->getJson('/api/team/activity')->assertOk();
        $this->actingAs($manager)->getJson('/api/expenses')->assertOk();
        $this->actingAs($manager)->getJson('/api/providers')->assertOk();
        $this->actingAs($manager)->get('/team')->assertForbidden();
        $this->actingAs($manager)->get('/integrations/gmail/connect')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/financial-summary')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/provider-connections')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/terminals')->assertOk()->assertJsonMissingPath('data.0.terminal_identifier');
        $this->assertSame($agent->id, $manager->businessAgentProfile()->id);
        $this->assertSame('owner', $owner->businessRole());
    }

    public function test_only_one_attendant_can_have_an_open_shift_per_terminal(): void
    {
        [, $agent, $firstMember, $terminal] = $this->createTeam('attendant');
        [, , $secondMember] = $this->createTeam('attendant');
        $secondMember->teamMembership->update(['agent_profile_id' => $agent->id]);
        $secondMember->teamMembership->terminals()->attach($terminal->id);
        $firstMember->teamMembership->terminals()->attach($terminal->id);
        $this->actingAs($firstMember)->postJson('/api/staff/shifts', ['terminal_id' => $terminal->id])->assertCreated();
        $this->actingAs($secondMember)->postJson('/api/staff/shifts', ['terminal_id' => $terminal->id])->assertStatus(409);
    }

    private function createTeam(string $role): array
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $owner->id]);
        $member = User::factory()->create(['email_verified_at' => now()]);
        BusinessMembership::create([
            'agent_profile_id' => $agent->id,
            'user_id' => $member->id,
            'invited_by' => $owner->id,
            'role' => $role,
            'is_active' => true,
        ]);
        $provider = Provider::factory()->create();
        $terminal = Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id]);

        return [$owner, $agent, $member, $terminal, $provider];
    }
}
