<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_another_agents_transaction(): void
    {
        $owner = User::factory()->create();
        $ownerAgent = AgentProfile::create(['user_id' => $owner->id, 'business_name' => 'Owner']);
        $provider = Provider::create(['name' => 'OPay', 'slug' => 'opay']);
        $transaction = Transaction::create(['agent_profile_id' => $ownerAgent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => 1000, 'customer_charge' => 100, 'provider_fee' => 10, 'transaction_status' => 'successful', 'settlement_status' => 'pending', 'transaction_at' => now(), 'source' => 'demo']);
        $this->actingAs(User::factory()->create())->get('/transactions/'.$transaction->id)->assertForbidden();
    }

    public function test_user_without_agent_profile_cannot_open_transaction_import(): void
    {
        $this->actingAs(User::factory()->create())->get('/transactions/import')->assertNotFound();
    }

    public function test_customer_charge_override_preserves_imported_value(): void
    {
        $owner = User::factory()->create();
        $agent = AgentProfile::create(['user_id' => $owner->id, 'business_name' => 'Owner']);
        $provider = Provider::create(['name' => 'OPay', 'slug' => 'opay']);
        $transaction = Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => 1000, 'customer_charge' => 100, 'imported_customer_charge' => 100, 'calculated_customer_charge' => 100, 'customer_charge_source' => 'imported', 'provider_fee' => 10, 'transaction_status' => 'successful', 'settlement_status' => 'pending', 'transaction_at' => now(), 'source' => 'csv']);
        $this->actingAs($owner)->patch('/transactions/'.$transaction->id.'/customer-charge', ['customer_charge_override' => '125'])->assertRedirect();
        $transaction->refresh();
        $this->assertSame('125.00', (string) $transaction->customer_charge);
        $this->assertSame('100.00', (string) $transaction->imported_customer_charge);
        $this->assertSame('manual', $transaction->customer_charge_source->value);
    }
}
