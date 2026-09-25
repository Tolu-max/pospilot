<?php

namespace Tests\Unit;

use App\Enums\ChargeType;
use App\Enums\SettlementStatus;
use App\Enums\TransactionAdjustmentDirection;
use App\Enums\TransactionAdjustmentSource;
use App\Enums\TransactionAdjustmentType;
use App\Enums\TransactionStatus;
use App\Models\AgentProfile;
use App\Models\ChargeRule;
use App\Models\Expense;
use App\Models\Provider;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\TransactionAdjustment;
use App\Models\User;
use App\Services\ChargeCalculationService;
use App\Services\DailyClosingService;
use App\Services\EarningsService;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialLogicTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): AgentProfile
    {
        return AgentProfile::create(['user_id' => User::factory()->create()->id, 'business_name' => 'Test Agent']);
    }

    private function provider(): Provider
    {
        return Provider::create(['name' => 'OPay', 'slug' => 'opay']);
    }

    private function rule(AgentProfile $agent, array $values): ChargeRule
    {
        return ChargeRule::create($values + ['agent_profile_id' => $agent->id, 'charge_type' => ChargeType::Fixed->value, 'charge_value' => 100, 'minimum_amount' => 1, 'maximum_amount' => null, 'active' => true]);
    }

    public function test_fixed_charge_rule_matching(): void
    {
        $agent = $this->agent();
        $this->rule($agent, ['minimum_amount' => 1, 'maximum_amount' => 5000, 'charge_value' => 100]);
        $this->rule($agent, ['minimum_amount' => 5001, 'maximum_amount' => 10000, 'charge_value' => 200]);
        $service = new ChargeCalculationService;
        $this->assertSame('100.00', $service->calculate($agent, '5000'));
        $this->assertSame('200.00', $service->calculate($agent, '5001'));
    }

    public function test_percentage_charge_calculation_is_decimal_safe(): void
    {
        $agent = $this->agent();
        $this->rule($agent, ['charge_type' => ChargeType::Percentage->value, 'charge_value' => '1.5', 'minimum_amount' => 1]);
        $this->assertSame('15.00', (new ChargeCalculationService)->calculate($agent, '1000'));
    }

    public function test_provider_specific_and_priority_rules_resolve_overlaps(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $this->rule($agent, ['minimum_amount' => 1, 'maximum_amount' => 10000, 'charge_value' => 100, 'priority' => 1]);
        $this->rule($agent, ['provider_id' => $provider->id, 'minimum_amount' => 5000, 'maximum_amount' => null, 'charge_value' => 250, 'priority' => 2]);
        $this->assertSame('250.00', (new ChargeCalculationService)->calculate($agent, '7500', $provider->id));
    }

    public function test_customer_charge_is_separate_from_principal(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $transaction = Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'withdrawal', 'amount' => '10000.00', 'customer_charge' => '200.00', 'provider_fee' => '50.00', 'transaction_status' => TransactionStatus::Successful->value, 'settlement_status' => SettlementStatus::Settled->value, 'transaction_at' => now(), 'source' => 'demo']);
        $this->assertSame('10000.00', (string) $transaction->amount);
        $this->assertSame('200.00', (string) $transaction->customer_charge);
    }

    public function test_transaction_adjustment_factory_creates_a_bound_transaction_without_mass_assigning_ownership(): void
    {
        $adjustment = TransactionAdjustment::factory()->create();

        $this->assertNotNull($adjustment->transaction_id);
        $this->assertModelExists($adjustment->transaction);
        $this->assertArrayNotHasKey('transaction_id', $adjustment->getFillable());
    }

    public function test_earnings_exclude_failed_and_reversed_transactions(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        foreach ([TransactionStatus::Successful, TransactionStatus::Failed, TransactionStatus::Reversed] as $status) {
            Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => 10000, 'customer_charge' => 200, 'provider_fee' => 50, 'transaction_status' => $status->value, 'settlement_status' => SettlementStatus::Pending->value, 'transaction_at' => now(), 'source' => 'demo']);
        } Expense::create(['agent_profile_id' => $agent->id, 'amount' => 25, 'category' => 'data', 'expense_date' => now()]);
        $summary = (new EarningsService)->summarize($agent);
        $this->assertSame('125.00', $summary['estimated_net_earnings']);
        $this->assertSame(1, $summary['successful_transaction_count']);
    }

    public function test_earnings_use_complete_components_without_double_counting_legacy_provider_fee(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $transaction = Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => '10000.00', 'customer_charge' => '200.00', 'provider_fee' => '30.00', 'transaction_status' => 'successful', 'settlement_status' => 'settled', 'transaction_at' => now(), 'source' => 'api']);
        $transaction->provider_fee_components_complete = true;
        $transaction->save();
        $transaction->adjustments()->createMany([
            ['type' => TransactionAdjustmentType::ProviderFee, 'amount' => '20.00', 'direction' => TransactionAdjustmentDirection::Debit, 'source' => TransactionAdjustmentSource::Provider, 'provider_component_code' => 'fee'],
            ['type' => TransactionAdjustmentType::VatTax, 'amount' => '5.00', 'direction' => TransactionAdjustmentDirection::Debit, 'source' => TransactionAdjustmentSource::Provider, 'provider_component_code' => 'vat'],
            ['type' => TransactionAdjustmentType::Levy, 'amount' => '5.00', 'direction' => TransactionAdjustmentDirection::Debit, 'source' => TransactionAdjustmentSource::Provider, 'provider_component_code' => 'levy'],
            ['type' => TransactionAdjustmentType::CustomerCharge, 'amount' => '200.00', 'direction' => TransactionAdjustmentDirection::Credit, 'source' => TransactionAdjustmentSource::Provider, 'provider_component_code' => 'customer-charge'],
            ['type' => TransactionAdjustmentType::Commission, 'amount' => '4.00', 'direction' => TransactionAdjustmentDirection::Credit, 'source' => TransactionAdjustmentSource::Provider, 'provider_component_code' => 'commission'],
        ]);

        $summary = (new EarningsService)->summarize($agent);

        $this->assertSame('30.00', $summary['provider_fees']);
        $this->assertSame('200.00', $summary['customer_charges']);
        $this->assertSame('4.00', $summary['other_transaction_credits']);
        $this->assertSame('174.00', $summary['estimated_net_earnings']);
        $this->assertSame('174.00', (new EarningsService)->transactionContribution($transaction->fresh()));
        $this->assertTrue($summary['is_final']);
    }

    public function test_missing_provider_fee_marks_transaction_and_aggregate_earnings_provisional(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $transaction = Transaction::create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'transaction_type' => 'transfer',
            'amount' => '10000.00',
            'customer_charge' => '200.00',
            'customer_charge_source' => 'imported',
            'provider_fee' => '0.00',
            'transaction_status' => 'successful',
            'settlement_status' => 'pending',
            'transaction_at' => now(),
            'source' => 'webhook',
            'metadata' => ['provider_fee_supplied' => false],
        ]);
        $transaction->provider_fee_supplied = false;
        $transaction->save();

        $earnings = new EarningsService;
        $transactionStatus = $earnings->transactionFinancialStatus($transaction);
        $summary = $earnings->summarize($agent);

        $this->assertSame('provisional', $transactionStatus['financial_data_status']);
        $this->assertSame(['provider_fee_missing'], $transactionStatus['reasons']);
        $this->assertFalse($transactionStatus['is_final']);
        $this->assertSame('200.00', $summary['estimated_net_earnings']);
        $this->assertSame('provisional', $summary['earnings_status']);
        $this->assertSame('provisional', $summary['financial_data_status']);
        $this->assertFalse($summary['is_final']);
        $this->assertSame(1, $summary['provisional_transaction_count']);
        $this->assertSame([['code' => 'provider_fee_missing', 'transaction_count' => 1]], $summary['provisional_reasons']);
    }

    public function test_earnings_summary_counts_calculated_and_manual_charge_provenance_independently_of_completeness(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        foreach ([['calculated', null], ['manual', '150.00']] as [$source, $override]) {
            $transaction = Transaction::create([
                'agent_profile_id' => $agent->id,
                'provider_id' => $provider->id,
                'transaction_type' => 'transfer',
                'amount' => '1000.00',
                'customer_charge' => $override ?? '100.00',
                'customer_charge_source' => $source,
                'customer_charge_override' => $override,
                'provider_fee' => '10.00',
                'transaction_status' => 'successful',
                'settlement_status' => 'settled',
                'transaction_at' => now(),
                'source' => 'api',
            ]);
            $transaction->provider_fee_components_complete = true;
            $transaction->save();
        }

        $summary = (new EarningsService)->summarize($agent);

        $this->assertSame('final', $summary['earnings_status']);
        $this->assertSame(1, $summary['calculated_transaction_count']);
        $this->assertSame(1, $summary['manually_overridden_transaction_count']);
        $this->assertSame('manually_overridden', $summary['financial_data_status']);
    }

    public function test_legacy_fee_remains_authoritative_for_partial_provider_components(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $transaction = Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => '10000.00', 'customer_charge' => '200.00', 'provider_fee' => '50.00', 'transaction_status' => 'successful', 'settlement_status' => 'settled', 'transaction_at' => now(), 'source' => 'api']);
        $transaction->adjustments()->create(['type' => TransactionAdjustmentType::VatTax, 'amount' => '5.00', 'direction' => TransactionAdjustmentDirection::Debit, 'source' => TransactionAdjustmentSource::Provider]);

        $summary = (new EarningsService)->summarize($agent);

        $this->assertSame('50.00', $summary['provider_fees']);
        $this->assertSame('150.00', $summary['estimated_net_earnings']);
    }

    public function test_explicit_pospilot_adjustments_add_to_legacy_fee_when_breakdown_is_incomplete(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $transaction = Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => '10000.00', 'customer_charge' => '200.00', 'provider_fee' => '50.00', 'transaction_status' => 'successful', 'settlement_status' => 'settled', 'transaction_at' => now(), 'source' => 'manual']);
        $transaction->adjustments()->create(['type' => TransactionAdjustmentType::VatTax, 'amount' => '2.50', 'direction' => TransactionAdjustmentDirection::Debit, 'source' => TransactionAdjustmentSource::Calculated, 'calculation_rule' => 'pospilot.vat_rule_v1']);

        $summary = (new EarningsService)->summarize($agent);

        $this->assertSame('52.50', $summary['provider_fees']);
        $this->assertSame('147.50', $summary['estimated_net_earnings']);
    }

    public function test_manual_charge_override_takes_precedence_over_adjustment_customer_charge(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $transaction = Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => '10000.00', 'customer_charge' => '200.00', 'customer_charge_override' => '125.00', 'provider_fee' => '50.00', 'transaction_status' => 'successful', 'settlement_status' => 'settled', 'transaction_at' => now(), 'source' => 'api']);
        $transaction->adjustments()->create(['type' => TransactionAdjustmentType::CustomerCharge, 'amount' => '200.00', 'direction' => TransactionAdjustmentDirection::Credit, 'source' => TransactionAdjustmentSource::Provider]);

        $this->assertSame('75.00', (new EarningsService)->transactionContribution($transaction));
    }

    public function test_component_deductions_are_shared_by_earnings_reconciliation_and_daily_closing(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $transaction = Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'transaction_type' => 'transfer', 'amount' => '10000.00', 'customer_charge' => '200.00', 'provider_fee' => '10.00', 'transaction_status' => 'successful', 'settlement_status' => 'settled', 'transaction_at' => '2026-09-24 10:00:00', 'source' => 'api']);
        $transaction->provider_fee_components_complete = true;
        $transaction->save();
        $transaction->adjustments()->createMany([
            ['type' => TransactionAdjustmentType::ProviderFee, 'amount' => '10.00', 'direction' => TransactionAdjustmentDirection::Debit, 'source' => TransactionAdjustmentSource::Provider],
            ['type' => TransactionAdjustmentType::VatTax, 'amount' => '2.00', 'direction' => TransactionAdjustmentDirection::Debit, 'source' => TransactionAdjustmentSource::Provider],
        ]);
        $service = new ReconciliationService;
        $date = CarbonImmutable::parse('2026-09-24');
        $closing = (new DailyClosingService)->preview($agent, $date);

        $this->assertSame('9988.00', $service->expectedSettlementFor($agent, $provider->id, date: '2026-09-24'));
        $this->assertSame('-9988.00', $closing['expected_electronic_position']);
        $this->assertSame('12.00', $closing['provider_fees']);
        $this->assertSame('188.00', (new EarningsService)->transactionContribution($transaction->fresh()));
    }

    public function test_reconciliation_reports_settlement_discrepancy(): void
    {
        $agent = $this->agent();
        $provider = $this->provider();
        $settlement = Settlement::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'expected_amount' => '10000.00', 'actual_amount' => '9500.00', 'settlement_date' => now(), 'status' => SettlementStatus::Unreconciled->value]);
        $result = (new ReconciliationService)->compare($settlement);
        $this->assertSame('-500.00', $result['discrepancy']);
        $this->assertFalse($result['is_reconciled']);
        $this->assertSame('unreconciled', $result['status']);
    }
}
