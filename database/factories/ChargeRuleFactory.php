<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\ChargeRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChargeRuleFactory extends Factory
{
    protected $model = ChargeRule::class;

    public function definition(): array
    {
        return ['agent_profile_id' => AgentProfile::factory(), 'provider_id' => null, 'minimum_amount' => 1, 'maximum_amount' => 5000, 'charge_type' => 'fixed', 'charge_value' => 100, 'priority' => 0, 'active' => true];
    }
}
