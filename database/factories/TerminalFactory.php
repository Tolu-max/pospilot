<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\Terminal;
use Illuminate\Database\Eloquent\Factories\Factory;

class TerminalFactory extends Factory
{
    protected $model = Terminal::class;

    public function definition(): array
    {
        return ['agent_profile_id' => AgentProfile::factory(), 'provider_id' => Provider::factory(), 'name' => 'Main terminal', 'terminal_identifier' => null, 'active' => true];
    }
}
