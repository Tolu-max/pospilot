<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentProfileFactory extends Factory
{
    protected $model = AgentProfile::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'business_name' => fake()->company(), 'phone' => fake()->numerify('080########'), 'country' => 'Nigeria', 'currency' => 'NGN', 'location' => fake()->city()];
    }
}
