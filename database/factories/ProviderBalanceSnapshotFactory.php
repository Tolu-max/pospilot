<?php

namespace Database\Factories;

use App\Models\DailyClosing;
use App\Models\Provider;
use App\Models\ProviderBalanceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderBalanceSnapshot>
 */
class ProviderBalanceSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['daily_closing_id' => DailyClosing::factory(), 'provider_id' => Provider::factory(), 'terminal_id' => null, 'expected_balance' => 0, 'actual_balance' => null, 'difference' => null, 'source' => 'manual', 'metadata' => ['fictional' => true]];
    }
}
