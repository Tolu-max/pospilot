<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Services\ProviderCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProviderCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_catalog_seeder_creates_only_provider_reference_records_and_is_repeatable(): void
    {
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ProviderCatalogSeeder', '--no-interaction' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ProviderCatalogSeeder', '--no-interaction' => true]);

        $this->assertDatabaseCount('providers', 4);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('agent_profiles', 0);
        $this->assertDatabaseCount('terminals', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('settlements', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('daily_closings', 0);

        $this->assertSame(
            ['moniepoint', 'opay', 'other', 'palmpay'],
            Provider::query()->orderBy('slug')->pluck('slug')->all(),
        );
    }

    public function test_documented_provider_access_is_separate_from_connector_implementation_readiness(): void
    {
        $opay = Provider::factory()->make(['slug' => 'opay']);
        $moniepoint = Provider::factory()->make(['slug' => 'moniepoint']);
        $capabilities = new ProviderCapabilityService;

        $opayCapabilities = $capabilities->for($opay);
        $moniepointCapabilities = $capabilities->for($moniepoint);

        $this->assertSame('documented', $opayCapabilities['api_transaction_sync']);
        $this->assertSame('planned', $opayCapabilities['connector_implementation']);
        $this->assertSame('supported', $moniepointCapabilities['connection_test']);
        $this->assertSame('planned', $moniepointCapabilities['connector_implementation']);
        $this->assertSame('unverified', $moniepointCapabilities['live_tested']);
    }
}
