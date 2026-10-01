<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\AgentProfile;
use App\Models\ChargeRule;
use App\Models\Provider;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_onboarding_state_can_be_updated(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->patchJson('/api/agent/profile', ['business_name' => 'New Shop', 'onboarding_state' => 'in_progress'])->assertOk()->assertJsonPath('business_name', 'New Shop')->assertJsonPath('onboarding_state', 'in_progress');
    }

    public function test_terminal_and_connection_records_are_scoped_to_the_current_agent(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create();
        $other = AgentProfile::factory()->create();
        Terminal::factory()->create(['agent_profile_id' => $other->id, 'provider_id' => $provider->id]);
        ProviderConnection::factory()->create(['agent_profile_id' => $other->id, 'provider_id' => $provider->id]);
        $this->actingAs($user)->getJson('/api/terminals')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/provider-connections')->assertOk()->assertJsonCount(0, 'data');
        $response = $this->actingAs($user)->postJson('/api/terminals', ['provider_id' => $provider->id, 'name' => 'My Terminal']);
        $response->assertCreated();
        $this->assertDatabaseHas('terminals', ['agent_profile_id' => $agent->id, 'name' => 'My Terminal']);
    }

    public function test_unassigned_statement_transaction_can_be_assigned_after_terminal_creation(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create();
        $account = ProviderAccount::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id]);
        $transaction = Transaction::factory()->create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'provider_account_id' => $account->id,
            'terminal_id' => null,
            'source' => 'statement',
        ]);

        $terminalResponse = $this->actingAs($user)->postJson('/api/terminals', [
            'provider_id' => $provider->id,
            'name' => 'New OPay terminal',
        ])->assertCreated()->assertJsonPath('provider_account_id', $account->id);

        $this->patchJson('/transactions/'.$transaction->id.'/terminal', [
            'terminal_id' => $terminalResponse->json('id'),
        ])->assertOk()->assertJsonPath('terminal_id', $terminalResponse->json('id'));

        $this->assertSame($terminalResponse->json('id'), $transaction->fresh()->terminal_id);
        $this->assertNotEmpty($transaction->fresh()->metadata['terminal_assignment_history']);
    }

    public function test_charge_preview_obeys_boundary_and_priority_rules(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        ChargeRule::create(['agent_profile_id' => $agent->id, 'minimum_amount' => 1, 'maximum_amount' => 5000, 'charge_type' => 'fixed', 'charge_value' => 100, 'priority' => 1, 'active' => true]);
        ChargeRule::create(['agent_profile_id' => $agent->id, 'minimum_amount' => 5001, 'maximum_amount' => 10000, 'charge_type' => 'fixed', 'charge_value' => 200, 'priority' => 1, 'active' => true]);
        $this->actingAs($user)->getJson('/api/charge-rules/preview?amount=5000')->assertOk()->assertJsonPath('charge', '100.00');
        $this->actingAs($user)->getJson('/api/charge-rules/preview?amount=5001')->assertOk()->assertJsonPath('charge', '200.00');
        $this->actingAs($user)->postJson('/api/charge-rules', ['minimum_amount' => 4000, 'maximum_amount' => 6000, 'charge_type' => 'fixed', 'charge_value' => 150, 'priority' => 1])->assertStatus(422);
    }

    public function test_transaction_filters_and_json_charge_override_are_authorized(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $other = User::factory()->create();
        $otherAgent = AgentProfile::factory()->create(['user_id' => $other->id]);
        $provider = Provider::factory()->create();
        $transaction = Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'external_reference' => 'REF-123', 'transaction_status' => TransactionStatus::Successful->value]);
        Transaction::factory()->create(['agent_profile_id' => $otherAgent->id, 'provider_id' => $provider->id]);
        $this->actingAs($user)->getJson('/api/transactions?reference=REF-123')->assertOk()->assertJsonPath('data.0.external_reference', 'REF-123');
        $this->actingAs($user)->patchJson('/api/transactions/'.$transaction->id.'/customer-charge', ['customer_charge_override' => '125'])->assertOk()->assertJsonPath('customer_charge_source', 'manual');
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'customer_charge_override_by' => $user->id]);
        $this->actingAs($other)->patchJson('/api/transactions/'.$transaction->id.'/customer-charge', ['customer_charge_override' => '500'])->assertForbidden();
    }

    public function test_expense_changes_are_reflected_in_financial_summary(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create();
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'amount' => 1000, 'customer_charge' => 100, 'provider_fee' => 10, 'transaction_status' => 'successful', 'transaction_at' => now()]);
        $this->actingAs($user)->postJson('/api/expenses', ['amount' => '25.00', 'category' => 'power', 'expense_date' => now()->toDateString()])->assertCreated();
        $this->actingAs($user)->getJson('/api/financial-summary')->assertOk()->assertJsonPath('expenses', '25.00')->assertJsonPath('estimated_net_earnings', '65.00');
    }

    public function test_financial_status_counts_exclude_wallet_activity_and_other_businesses(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $otherAgent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        foreach (['successful', 'pending', 'failed', 'reversed'] as $status) {
            Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_status' => $status, 'transaction_at' => now()]);
        }
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_status' => 'successful', 'transaction_at' => now(), 'metadata' => ['activity_scope' => 'personal_wallet']]);
        Transaction::factory()->create(['agent_profile_id' => $otherAgent->id, 'provider_id' => $provider->id, 'transaction_status' => 'failed', 'transaction_at' => now()]);

        $this->actingAs($user)->getJson('/api/financial-summary?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('status_counts.successful', 1)
            ->assertJsonPath('status_counts.pending', 1)
            ->assertJsonPath('status_counts.failed', 1)
            ->assertJsonPath('status_counts.reversed', 1);
    }
}
