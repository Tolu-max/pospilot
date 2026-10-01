<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\ProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderAccount>
 */
class ProviderAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_profile_id' => AgentProfile::factory(),
            'provider_id' => Provider::factory(),
            'display_name' => fake()->company().' account',
            'account_type' => 'business_pos',
            'identifier_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'masked_identifier' => '••••'.fake()->numerify('####'),
        ];
    }
}
