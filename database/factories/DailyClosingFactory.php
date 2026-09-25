<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\DailyClosing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyClosing>
 */
class DailyClosingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['agent_profile_id' => AgentProfile::factory(), 'closing_date' => today(), 'opening_cash' => 10000, 'entered_closing_cash' => null, 'expected_cash' => 10000, 'expected_electronic_position' => 0, 'actual_electronic_position' => null, 'transaction_volume' => 0, 'customer_charges' => 0, 'provider_fees' => 0, 'expenses' => 0, 'total_variance' => 0, 'status' => 'draft', 'variance_status' => 'balanced', 'notes' => null, 'closed_by' => null, 'closed_at' => null];
    }
}
