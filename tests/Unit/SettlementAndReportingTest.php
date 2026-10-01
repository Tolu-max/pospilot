<?php

namespace Tests\Unit;

use App\Imports\SettlementCsvImporterFactory;
use App\Models\AgentProfile;
use App\Models\Expense;
use App\Models\Provider;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Services\EarningsService;
use App\Services\ReconciliationService;
use App\Services\SettlementIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementAndReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_settlement_csv_is_normalized_and_repeat_import_is_idempotent(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::create(['name' => 'OPay', 'slug' => 'opay', 'status' => 'active']);
        $importer = (new SettlementCsvImporterFactory)->make($provider);
        $row = $importer->normalize(['Settlement ID', 'Settlement Date', 'Expected Settlement', 'Actual Settlement'], ['SET-1', '2026-09-23', '10000', '9800'], 2, $provider);
        $this->assertTrue($row['valid']);
        $data = $row['normalized_data'];
        $this->assertNull($data->providerFee);
        $service = new SettlementIngestionService;
        $this->assertSame('imported', $service->ingest($agent, $data)['status']);
        $this->assertSame('duplicate', $service->ingest($agent, $data)['status']);
        $this->assertSame(1, Settlement::count());
        $this->assertFalse(Settlement::firstOrFail()->provider_fee_supplied);
        $this->assertSame('0.00', Settlement::firstOrFail()->provider_fee);
    }

    public function test_exact_and_partial_reconciliation_are_distinguished(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => '10000', 'customer_charge' => '200', 'provider_fee' => '50', 'transaction_status' => 'successful', 'settlement_status' => 'pending', 'transaction_at' => '2026-09-23', 'source' => 'demo']);
        $service = new ReconciliationService;
        $exact = Settlement::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'expected_amount' => '9950', 'actual_amount' => '9950', 'settlement_date' => '2026-09-23']);
        $partial = Settlement::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'expected_amount' => '9950', 'actual_amount' => '9500', 'settlement_date' => '2026-09-23']);
        $this->assertSame('reconciled', $service->compare($exact)['outcome']);
        $this->assertSame('partially_reconciled', $service->compare($partial)['outcome']);
    }

    public function test_missing_settlement_provider_fee_remains_unknown_in_the_api(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        $settlement = Settlement::factory()->create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'provider_fee' => '0.00',
            'provider_fee_supplied' => false,
        ]);

        $this->actingAs($agent->user)->getJson('/api/settlements/'.$settlement->id)
            ->assertOk()
            ->assertJsonPath('settlement.provider_fee', null)
            ->assertJsonPath('settlement.provider_fee_known', false);
    }

    public function test_earnings_use_successful_transactions_and_expenses_only(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        foreach (['successful', 'failed', 'reversed', 'pending'] as $status) {
            Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => '10000', 'customer_charge' => '200', 'provider_fee' => '50', 'transaction_status' => $status, 'settlement_status' => 'pending', 'transaction_at' => '2026-09-23', 'source' => 'demo']);
        } Expense::create(['agent_profile_id' => $agent->id, 'amount' => '25', 'category' => 'power', 'expense_date' => '2026-09-23']);
        $summary = (new EarningsService)->summarize($agent);
        $this->assertSame('125.00', $summary['estimated_net_earnings']);
        $this->assertSame(1, $summary['successful_transaction_count']);
    }

    public function test_reversed_and_pending_transactions_without_settlement_appear_as_review_issues(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create(['name' => 'OPay']);
        foreach (['reversed' => '10000.00', 'pending' => '2500.00'] as $status => $amount) {
            Transaction::create([
                'agent_profile_id' => $agent->id,
                'provider_id' => $provider->id,
                'transaction_type' => 'transfer',
                'amount' => $amount,
                'transaction_status' => $status,
                'settlement_status' => 'pending',
                'transaction_at' => '2026-09-23 12:00:00',
                'source' => 'statement',
            ]);
        }

        $issues = (new ReconciliationService)->issuesForAgent(
            $agent,
            CarbonImmutable::parse('2026-09-23'),
            CarbonImmutable::parse('2026-09-23'),
        );

        $this->assertCount(2, $issues);
        $this->assertSame('reversed_transaction', $issues[0]['type']);
        $this->assertSame('10000.00', $issues[0]['amount']);
        $this->assertSame('transaction_pending', $issues[1]['type']);
        $this->assertSame('2500.00', $issues[1]['amount']);
    }
}
