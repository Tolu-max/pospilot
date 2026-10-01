<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Expense;
use App\Models\Provider;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_profitability_is_business_scoped_and_marks_missing_fees_and_unallocated_expenses_provisional(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $owner->id]);
        $provider = Provider::factory()->create(['name' => 'OPay']);
        $terminal = Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'name' => 'Main counter']);
        Transaction::factory()->for($agent)->create([
            'provider_id' => $provider->id,
            'terminal_id' => $terminal->id,
            'amount' => '10000.00',
            'customer_charge' => '200.00',
            'provider_fee' => '0.00',
            'provider_fee_supplied' => false,
            'provider_fee_components_complete' => false,
            'transaction_at' => today()->setTime(12, 0),
        ]);
        Expense::factory()->for($agent)->create(['terminal_id' => $terminal->id, 'amount' => '15.00', 'expense_date' => today()]);
        Expense::factory()->for($agent)->create(['terminal_id' => null, 'amount' => '25.00', 'expense_date' => today()]);

        $otherOwner = User::factory()->create(['email_verified_at' => now()]);
        $otherAgent = AgentProfile::factory()->create(['user_id' => $otherOwner->id]);
        $otherProvider = Provider::factory()->create();
        $otherTerminal = Terminal::factory()->create(['agent_profile_id' => $otherAgent->id, 'provider_id' => $otherProvider->id]);
        Transaction::factory()->for($otherAgent)->create(['provider_id' => $otherProvider->id, 'terminal_id' => $otherTerminal->id]);

        $response = $this->actingAs($owner)->getJson('/api/terminal-profitability?from='.today()->toDateString().'&to='.today()->toDateString())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Main counter', $response->json('data.0.terminal'));
        $this->assertSame('10000.00', $response->json('data.0.transaction_volume'));
        $this->assertSame('15.00', $response->json('data.0.allocated_expenses'));
        $this->assertSame('25.00', $response->json('unallocated_expenses'));
        $this->assertFalse($response->json('data.0.provider_fee_known'));
        $this->assertFalse($response->json('data.0.is_final'));
        $this->assertSame(1, $response->json('data.0.transaction_count'));
    }
}
