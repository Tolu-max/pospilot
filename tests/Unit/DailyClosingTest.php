<?php

namespace Tests\Unit;

use App\Models\AgentProfile;
use App\Models\Expense;
use App\Models\Provider;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DailyClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_closing_with_cash_and_provider_balance_finalizes(): void
    {
        [$agent, $provider] = $this->agentProvider();
        $date = now()->startOfDay();
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'withdrawal', 'amount' => '1000.00', 'customer_charge' => '100.00', 'provider_fee' => '10.00', 'transaction_at' => $date]);
        $service = new DailyClosingService;
        $closing = $service->saveDraft($agent, $date->toImmutable(), ['opening_cash' => '1000.00', 'entered_closing_cash' => '100.00']);
        $service->saveProviderBalance($agent, $closing, ['provider_id' => $provider->id, 'actual_balance' => '990.00']);
        $final = $service->finalize($agent, $closing, $agent->user_id);
        $this->assertSame('finalized', $final->status->value);
        $this->assertSame('balanced', $final->variance_status->value);
        $this->assertSame('0.00', (string) $final->total_variance);
    }

    public function test_provider_shortage_and_surplus_are_reported_per_provider_and_terminal(): void
    {
        [$agent, $provider] = $this->agentProvider();
        $otherProvider = Provider::factory()->create();
        $terminal = Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id]);
        $date = now()->startOfDay();
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'terminal_id' => $terminal->id, 'transaction_type' => 'withdrawal', 'amount' => 100, 'provider_fee' => 5, 'customer_charge' => 10, 'transaction_at' => $date]);
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $otherProvider->id, 'transaction_type' => 'withdrawal', 'amount' => 200, 'provider_fee' => 2, 'customer_charge' => 10, 'transaction_at' => $date]);
        $service = new DailyClosingService;
        $closing = $service->saveDraft($agent, $date->toImmutable(), []);
        $service->saveProviderBalance($agent, $closing, ['provider_id' => $provider->id, 'terminal_id' => $terminal->id, 'actual_balance' => '75']);
        $service->saveProviderBalance($agent, $closing, ['provider_id' => $otherProvider->id, 'actual_balance' => '208']);
        $result = $service->preview($agent, $date->toImmutable());
        $types = collect($result['reasons'])->pluck('type')->all();
        $this->assertContains('provider_balance_below_expected', $types);
        $this->assertContains('provider_balance_above_expected', $types);
        $this->assertSame('-10.00', (string) $result['total_variance']);
    }

    public function test_cash_discrepancy_and_expenses_are_included(): void
    {
        [$agent, $provider] = $this->agentProvider();
        $date = now()->startOfDay();
        Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'withdrawal', 'amount' => 500, 'customer_charge' => 20, 'provider_fee' => 5, 'transaction_at' => $date]);
        Expense::create(['agent_profile_id' => $agent->id, 'amount' => 50, 'category' => 'power', 'expense_date' => $date]);
        $service = new DailyClosingService;
        $closing = $service->saveDraft($agent, $date->toImmutable(), ['opening_cash' => '1000', 'entered_closing_cash' => '400']);
        $result = $service->preview($agent, $date->toImmutable());
        $this->assertSame('470.00', (string) $result['expected_cash']);
        $this->assertSame('-70.00', (string) $result['total_variance']);
        $this->assertContains('cash_mismatch', collect($result['reasons'])->pluck('type')->all());
    }

    public function test_pending_and_reversed_transactions_create_explanations_without_being_expected(): void
    {
        [$agent, $provider] = $this->agentProvider();
        $date = now()->startOfDay();
        foreach (['pending', 'reversed'] as $status) {
            Transaction::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_status' => $status, 'transaction_at' => $date]);
        } $result = (new DailyClosingService)->preview($agent, $date->toImmutable());
        $types = collect($result['reasons'])->pluck('type')->all();
        $this->assertContains('pending_transaction', $types);
        $this->assertContains('reversal_affecting_expected_position', $types);
        $this->assertSame('0.00', (string) $result['expected_electronic_position']);
    }

    private function agentProvider(): array
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);

        return [$agent, Provider::factory()->create()];
    }
}
