<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return ['agent_profile_id' => AgentProfile::factory(), 'provider_id' => Provider::factory(), 'transaction_type' => 'transfer', 'amount' => 1000, 'customer_charge' => 100, 'customer_charge_override' => null, 'provider_fee' => 10, 'transaction_status' => 'successful', 'settlement_status' => 'pending', 'transaction_at' => now(), 'settled_at' => null, 'source' => 'demo', 'metadata' => ['fictional' => true]];
    }
}
