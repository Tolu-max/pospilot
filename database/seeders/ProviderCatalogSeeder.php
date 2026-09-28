<?php

namespace Database\Seeders;

use App\Enums\ProviderStatus;
use App\Models\Provider;
use Illuminate\Database\Seeder;

class ProviderCatalogSeeder extends Seeder
{
    /**
     * Seed only the real provider catalog required by onboarding and integrations.
     */
    public function run(): void
    {
        foreach ([
            ['name' => 'OPay', 'slug' => 'opay'],
            ['name' => 'Moniepoint', 'slug' => 'moniepoint'],
            ['name' => 'PalmPay', 'slug' => 'palmpay'],
            ['name' => 'Generic / Other', 'slug' => 'other'],
        ] as $provider) {
            Provider::query()->updateOrCreate(
                ['slug' => $provider['slug']],
                ['name' => $provider['name'], 'status' => ProviderStatus::Active],
            );
        }
    }
}
