<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessInsightTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_insight_sends_only_aggregate_financial_data_and_marks_missing_fees_provisional(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.cencori.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'You processed transactions today. Earnings are provisional because fees are incomplete.']]],
            ], 200),
        ]);
        config(['services.cencori.enabled' => true]);
        config(['services.cencori.api_key' => 'test-secret-key']);
        $user = User::factory()->create(['email_verified_at' => now(), 'name' => 'Private Agent Name', 'email' => 'private-agent@example.test']);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create();
        Transaction::factory()->create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'amount' => '12500.00',
            'customer_charge' => '250.00',
            'provider_fee' => '0.00',
            'metadata' => [
                'provider_fee_supplied' => false,
                'customer_name' => 'Private Customer Name',
                'customer_account_number' => '0123456789',
                'rrn' => 'PRIVATE-RRN-9988',
            ],
        ]);

        $this->actingAs($user)->postJson('/api/business-insight')
            ->assertOk()
            ->assertJsonPath('explanation', 'You processed transactions today. Earnings are provisional because fees are incomplete.');

        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];
        $payload = $request->data();
        $summary = json_decode($payload['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('https://api.cencori.com/v1/chat/completions', $request->url());
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertSame([
            'successful_transaction_count' => 1,
            'transaction_volume' => '12500.00',
            'customer_charges' => '250.00',
            'provider_fees' => null,
            'provider_fees_known' => false,
            'estimated_earnings' => '250.00',
            'earnings_provisional' => true,
            'expenses_total' => '0.00',
            'reconciliation_issue_count' => 1,
            'closing_variance' => null,
        ], $summary);
        $this->assertStringNotContainsString('Private Agent Name', $request->body());
        $this->assertStringNotContainsString('private-agent@example.test', $request->body());
        $this->assertStringNotContainsString('Private Customer Name', $request->body());
        $this->assertStringNotContainsString('0123456789', $request->body());
        $this->assertStringNotContainsString('PRIVATE-RRN-9988', $request->body());
    }

    public function test_business_insight_includes_previous_day_only_when_activity_exists(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.cencori.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Today is ready to review.']]]])]);
        config(['services.cencori.enabled' => true, 'services.cencori.api_key' => 'test-secret-key']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create();
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'amount' => '2400.00', 'transaction_at' => now()->subDay()]);

        $this->actingAs($user)->postJson('/api/business-insight')->assertOk();

        $request = Http::recorded()->first()[0];
        $summary = json_decode($request->data()['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $summary['previous_day']['successful_transaction_count']);
        $this->assertSame('2400.00', $summary['previous_day']['transaction_volume']);
    }

    public function test_business_insight_fails_gracefully_when_cencori_is_not_configured(): void
    {
        Http::preventStrayRequests();
        config(['services.cencori.enabled' => true, 'services.cencori.api_key' => null]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/business-insight')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Business Insight is temporarily unavailable. Your dashboard figures are still up to date.');

        Http::assertNothingSent();
    }

    public function test_business_insight_provider_failure_does_not_expose_provider_details(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.cencori.com/v1/chat/completions' => Http::response(['error' => ['message' => 'Sensitive upstream diagnostic']], 500),
        ]);
        config(['services.cencori.enabled' => true]);
        config(['services.cencori.api_key' => 'test-secret-key']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/business-insight')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Business Insight is temporarily unavailable. Your dashboard figures are still up to date.')
            ->assertJsonMissing(['error' => ['message' => 'Sensitive upstream diagnostic']]);
    }

    public function test_business_insight_is_limited_to_one_request_per_user_per_minute(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.cencori.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'A short fictional insight.']]],
            ], 200),
        ]);
        config(['services.cencori.enabled' => true]);
        config(['services.cencori.api_key' => 'test-secret-key']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/business-insight')->assertOk();
        $this->postJson('/api/business-insight')->assertTooManyRequests();

        Http::assertSentCount(1);
    }

    public function test_unauthenticated_user_cannot_request_business_insight(): void
    {
        $this->postJson('/api/business-insight')->assertUnauthorized();
    }

    public function test_disabled_business_insight_returns_coming_soon_without_sending_data_to_cencori(): void
    {
        Http::preventStrayRequests();
        config(['services.cencori.enabled' => false, 'services.cencori.api_key' => 'server-only-secret']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/business-insight')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Business Insight is coming soon.');

        Http::assertNothingSent();

        $this->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('businessInsightEnabled', false));
    }

    public function test_dashboard_exposes_only_business_insight_configuration_status(): void
    {
        config(['services.cencori.enabled' => true, 'services.cencori.api_key' => 'server-only-secret']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('businessInsightEnabled', true)
                ->missing('cencoriApiKey'))
            ->assertDontSee('server-only-secret');
    }
}
