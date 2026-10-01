<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\BusinessNotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessNotificationPreference>
 */
class BusinessNotificationPreferenceFactory extends Factory
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
            'closing_reminder_enabled' => false,
            'daily_summary_enabled' => false,
            'issue_reminder_enabled' => false,
        ];
    }
}
