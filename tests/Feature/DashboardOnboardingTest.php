<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\DailyClosing;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_without_agent_profile_is_sent_to_onboarding(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Start')
            ->where('userName', $user->name)
            ->where('workspaceReady', false));
    }

    public function test_user_with_in_progress_agent_profile_can_resume_onboarding(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $profile = AgentProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_state' => 'in_progress',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Start')
            ->where('profile.business_name', $profile->business_name)
            ->where('profile.onboarding_state', 'in_progress')
            ->where('workspaceReady', false));
    }

    public function test_completed_user_sees_the_workspace_navigation_state(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_state' => 'completed',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('workspaceReady', true));
    }

    public function test_dashboard_action_center_flags_unknown_fees_and_recorded_closing_variance(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_fee_supplied' => false]);
        DailyClosing::factory()->create(['agent_profile_id' => $agent->id, 'status' => 'finalized', 'total_variance' => '120.00']);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('actionItems', 2)
            ->where('actionItems.0.type', 'missing_fees')
            ->where('actionItems.1.type', 'closing_variance'));
    }

    public function test_incomplete_user_cannot_open_workspace_pages_or_data_apis(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_state' => 'in_progress',
        ]);

        $this->actingAs($user)->get('/profile')->assertRedirect('/dashboard');
        $this->actingAs($user)->get('/transactions')->assertRedirect('/dashboard');
        $this->actingAs($user)->getJson('/api/financial-summary')
            ->assertForbidden()
            ->assertJsonPath('redirect_to', route('dashboard'));
    }

    public function test_incomplete_user_can_access_provider_data_needed_for_setup(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_state' => 'in_progress',
        ]);

        $this->actingAs($user)->getJson('/api/providers')->assertOk();
        $this->actingAs($user)->getJson('/api/charge-rules')->assertOk()->assertJsonPath('data', []);
        $this->actingAs($user)->patchJson('/api/agent/profile', [
            'selected_provider_slugs' => ['moniepoint', 'opay', 'palmpay'],
        ])->assertOk();

        $this->assertSame(['moniepoint', 'opay', 'palmpay'], $user->agentProfile->fresh()->selected_provider_slugs);

        $feeBand = $this->actingAs($user)->postJson('/api/charge-rules', [
            'minimum_amount' => '1000',
            'maximum_amount' => '5000',
            'charge_type' => 'fixed',
            'charge_value' => '100',
            'priority' => 0,
            'active' => true,
        ])->assertCreated()->json();

        $this->actingAs($user)->patchJson('/api/charge-rules/'.$feeBand['id'], [
            'charge_value' => '150',
        ])->assertOk()->assertJsonPath('charge_value', '150.0000');
    }
}
