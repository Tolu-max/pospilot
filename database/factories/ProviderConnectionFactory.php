<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\ProviderConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderConnectionFactory extends Factory
{
    protected $model = ProviderConnection::class;

    public function definition(): array
    {
        return ['agent_profile_id' => AgentProfile::factory(), 'provider_id' => Provider::factory(), 'connection_type' => 'csv', 'connection_status' => 'active', 'provider_account_identifier' => null, 'provider_merchant_identifier' => null, 'last_synced_at' => null, 'last_sync_status' => null, 'last_sync_error' => null, 'metadata' => []];
    }
}
