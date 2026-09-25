<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\Settlement;
use Illuminate\Database\Eloquent\Factories\Factory;

class SettlementFactory extends Factory
{
    protected $model = Settlement::class;

    public function definition(): array
    {
        return ['agent_profile_id' => AgentProfile::factory(), 'provider_id' => Provider::factory(), 'terminal_id' => null, 'import_batch_id' => null, 'settlement_reference' => fake()->uuid(), 'gross_transaction_amount' => 1000, 'provider_fee' => 0, 'expected_amount' => 1000, 'actual_amount' => 1000, 'settlement_date' => today(), 'status' => 'settled', 'reconciliation_outcome' => 'reconciled', 'source' => 'demo', 'import_fingerprint' => null, 'metadata' => ['fictional' => true]];
    }
}
