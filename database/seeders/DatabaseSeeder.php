<?php

namespace Database\Seeders;

use App\Enums\ChargeType;
use App\Enums\SettlementStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\AgentProfile;
use App\Models\ChargeRule;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\Provider;
use App\Models\ProviderBalanceSnapshot;
use App\Models\ProviderConnection;
use App\Models\Settlement;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $agent = AgentProfile::create(['user_id' => $user->id, 'business_name' => 'Amina Digital Services', 'phone' => '08000000000', 'country' => 'Nigeria', 'currency' => 'NGN', 'location' => 'Ikeja, Lagos']);
        $providers = collect(['OPay' => 'opay', 'Moniepoint' => 'moniepoint', 'PalmPay' => 'palmpay', 'Generic / Other' => 'other'])->mapWithKeys(fn ($slug, $name) => [$name => Provider::create(['name' => $name, 'slug' => $slug])]);
        $providers->each(fn ($provider) => ProviderConnection::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'connection_type' => 'csv', 'connection_status' => 'active']));
        $terminals = $providers->mapWithKeys(fn ($provider, $name) => [$name => Terminal::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'name' => $name.' Main Terminal', 'terminal_identifier' => null])]);

        foreach ([['minimum_amount' => 1, 'maximum_amount' => 5000, 'charge_value' => 100], ['minimum_amount' => 5001, 'maximum_amount' => 10000, 'charge_value' => 200], ['minimum_amount' => 10001, 'maximum_amount' => 20000, 'charge_value' => 300], ['minimum_amount' => 20001, 'maximum_amount' => null, 'charge_value' => 400]] as $rule) {
            ChargeRule::create($rule + ['agent_profile_id' => $agent->id, 'charge_type' => ChargeType::Fixed->value, 'priority' => 10, 'active' => true]);
        }
        ChargeRule::create(['agent_profile_id' => $agent->id, 'provider_id' => $providers['PalmPay']->id, 'minimum_amount' => 10000, 'maximum_amount' => null, 'charge_type' => ChargeType::Percentage->value, 'charge_value' => 1.5, 'priority' => 20, 'active' => true]);

        $amounts = [1200, 4800, 6500, 10000, 12500, 18000, 23000, 3500, 7600, 15000];
        $statuses = array_fill(0, 30, TransactionStatus::Successful->value);
        $statuses[8] = TransactionStatus::Pending->value;
        $statuses[17] = TransactionStatus::Failed->value;
        $statuses[26] = TransactionStatus::Reversed->value;
        foreach ($statuses as $i => $status) {
            $provider = $providers->values()[$i % 3];
            $amount = $amounts[$i % count($amounts)];
            $at = now()->subDays(6 - intdiv($i, 5))->setTime(8 + ($i % 9), ($i * 7) % 60);
            $charge = match (true) {
                $amount <= 5000 => 100, $amount <= 10000 => 200, $amount <= 20000 => 300, default => 400
            };
            if ($provider->slug === 'palmpay' && $amount >= 10000) {
                $charge = round($amount * 0.015, 2);
            }
            Transaction::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'terminal_id' => $terminals[$provider->name]->id, 'external_reference' => 'DEMO-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'transaction_type' => $i % 4 === 0 ? 'withdrawal' : 'transfer', 'amount' => $amount, 'customer_charge' => $charge, 'provider_fee' => round($amount * (($i % 3 + 1) * 0.001), 2), 'transaction_status' => $status, 'settlement_status' => $status === TransactionStatus::Successful->value ? SettlementStatus::Settled->value : SettlementStatus::Pending->value, 'transaction_at' => $at, 'settled_at' => $status === TransactionStatus::Successful->value ? $at->copy()->addHours(4) : null, 'source' => TransactionSource::Demo->value, 'metadata' => ['fictional' => true]]);
        }
        Expense::create(['agent_profile_id' => $agent->id, 'amount' => 2500, 'category' => 'power', 'description' => 'Generator fuel', 'expense_date' => now()->subDays(2)]);
        Expense::create(['agent_profile_id' => $agent->id, 'amount' => 4000, 'category' => 'transport', 'description' => 'Cash collection transport', 'expense_date' => now()->subDays(1)]);
        $opayExpected = Transaction::where('agent_profile_id', $agent->id)->where('provider_id', $providers['OPay']->id)->where('transaction_status', TransactionStatus::Successful->value)->get()->reduce(fn (string $total, Transaction $transaction) => Money::add($total, Money::subtract($transaction->amount, $transaction->provider_fee)), '0.00');
        Settlement::create(['agent_profile_id' => $agent->id, 'provider_id' => $providers['OPay']->id, 'settlement_reference' => 'SET-DEMO-001', 'expected_amount' => $opayExpected, 'actual_amount' => $opayExpected - 500, 'settlement_date' => now()->subDays(1), 'status' => SettlementStatus::Unreconciled->value, 'metadata' => ['fictional' => true]]);
        Settlement::create(['agent_profile_id' => $agent->id, 'provider_id' => $providers['Moniepoint']->id, 'settlement_reference' => 'SET-DEMO-002', 'expected_amount' => 10000, 'actual_amount' => 10000, 'settlement_date' => now()->subDays(1), 'status' => SettlementStatus::Settled->value]);
        $palmExpected = Transaction::where('agent_profile_id', $agent->id)->where('provider_id', $providers['PalmPay']->id)->where('transaction_status', TransactionStatus::Successful->value)->whereDate('transaction_at', now()->subDays(1))->get()->reduce(fn (string $total, Transaction $transaction) => Money::add($total, Money::subtract($transaction->amount, $transaction->provider_fee)), '0.00');
        Settlement::create(['agent_profile_id' => $agent->id, 'provider_id' => $providers['PalmPay']->id, 'settlement_reference' => 'SET-DEMO-003', 'expected_amount' => $palmExpected, 'actual_amount' => Money::subtract($palmExpected, '50.00'), 'settlement_date' => now()->subDays(1), 'status' => SettlementStatus::Unreconciled->value, 'metadata' => ['fictional' => true, 'note' => 'Other PalmPay dates intentionally have no settlement record.']]);

        $closingDate = now()->subDays(1)->startOfDay();
        $closing = DailyClosing::create(['agent_profile_id' => $agent->id, 'closing_date' => $closingDate, 'expected_electronic_position' => '0.00', 'status' => 'finalized', 'variance_status' => 'needs_review', 'closed_by' => $user->id, 'closed_at' => now()]);
        $actualTotal = $expectedTotal = '0.00';
        foreach (['OPay' => '0.00', 'Moniepoint' => '50.00', 'PalmPay' => '-1500.00'] as $name => $variance) {
            $expected = Transaction::where('agent_profile_id', $agent->id)->where('provider_id', $providers[$name]->id)->where('transaction_status', TransactionStatus::Successful->value)->whereDate('transaction_at', $closingDate)->get()->reduce(fn (string $total, Transaction $transaction) => Money::add($total, Money::subtract($transaction->amount, $transaction->provider_fee)), '0.00');
            $actual = Money::add($expected, $variance);
            ProviderBalanceSnapshot::create(['daily_closing_id' => $closing->id, 'provider_id' => $providers[$name]->id, 'expected_balance' => $expected, 'actual_balance' => $actual, 'difference' => $variance, 'source' => TransactionSource::Demo->value, 'metadata' => ['fictional' => true]]);
            $expectedTotal = Money::add($expectedTotal, $expected);
            $actualTotal = Money::add($actualTotal, $actual);
        }
        $closing->update(['expected_electronic_position' => $expectedTotal, 'actual_electronic_position' => $actualTotal, 'total_variance' => Money::subtract($actualTotal, $expectedTotal)]);
    }
}
