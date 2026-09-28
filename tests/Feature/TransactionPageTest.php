<?php

namespace Tests\Feature;

use App\Enums\OnboardingState;
use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransactionPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_page_renders_when_agent_has_terminal_provider_join(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_state' => OnboardingState::Completed,
        ]);
        $provider = Provider::factory()->create();
        Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id]);

        $this->actingAs($user)->get('/transactions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Transactions/Index')
            ->where('providers.0.id', $provider->id)
            ->where('providers.0.name', $provider->name));
    }

    public function test_transaction_page_redirects_to_onboarding_when_agent_profile_is_missing(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/transactions')
            ->assertRedirect('/dashboard');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Onboarding/Start'));
    }

    public function test_transaction_page_redirects_to_onboarding_when_agent_setup_is_incomplete(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_state' => OnboardingState::InProgress,
        ]);

        $this->actingAs($user)
            ->get('/transactions')
            ->assertRedirect('/dashboard');
    }
}
