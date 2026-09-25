<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\ImportBatch;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return ['agent_profile_id' => AgentProfile::factory(), 'provider_id' => Provider::factory(), 'filename' => 'statement.csv', 'source' => 'csv', 'rows_detected' => 1, 'rows_imported' => 1, 'rows_duplicate' => 0, 'rows_failed' => 0, 'imported_at' => now(), 'status' => 'imported', 'metadata' => []];
    }
}
