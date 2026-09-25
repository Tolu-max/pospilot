<?php

namespace Tests\Unit;

use App\Contracts\PaymentProviderConnector;
use App\Data\NormalizedTransactionAdjustmentData;
use App\Data\NormalizedTransactionData;
use App\Enums\ChargeType;
use App\Enums\TransactionSource;
use App\Imports\CsvImporterFactory;
use App\Models\AgentProfile;
use App\Models\ChargeRule;
use App\Models\Provider;
use App\Models\ProviderConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ChargeCalculationService;
use App\Services\CsvImportService;
use App\Services\EarningsService;
use App\Services\SyncProviderTransactions;
use App\Services\TransactionIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

class TransactionIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function setupAgent(): array
    {
        $agent = AgentProfile::create(['user_id' => User::factory()->create()->id, 'business_name' => 'Ingestion Test Agent']);
        $provider = Provider::create(['name' => 'OPay', 'slug' => 'opay']);
        ChargeRule::create(['agent_profile_id' => $agent->id, 'minimum_amount' => 1, 'maximum_amount' => null, 'charge_type' => ChargeType::Fixed->value, 'charge_value' => 100, 'active' => true]);

        return [$agent, $provider];
    }

    private function data(Provider $provider, string $source = 'api', array $extra = []): NormalizedTransactionData
    {
        return NormalizedTransactionData::fromArray($provider, $extra + ['external_reference' => 'CROSS-001', 'amount' => '8000.00', 'provider_fee' => '24.00', 'transaction_status' => 'successful', 'transaction_at' => '2026-09-22T10:00:00+00:00', 'source' => $source, 'metadata' => ['provider_event_id' => 'evt-1']]);
    }

    public function test_normalized_api_and_csv_records_share_one_idempotency_identity(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $ingestion->ingest($agent, $this->data($provider), null);
        $service = new CsvImportService(new CsvImporterFactory, new ChargeCalculationService);
        $file = UploadedFile::fake()->createWithContent('later.csv', "Reference,Amount,Fee,Date\nCROSS-001,8000,24,2026-09-22 10:00:00\n");
        $preview = $service->preview($file, $agent, $provider);
        $result = $service->importPreview($agent, $provider, 'later.csv', $preview);
        $this->assertSame(0, $result['rows_imported']);
        $this->assertSame(1, $result['rows_duplicate']);
        $this->assertSame(1, Transaction::count());
    }

    public function test_ingestion_preserves_metadata_and_calculates_missing_charge(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $result = (new TransactionIngestionService(new ChargeCalculationService))->ingest($agent, $this->data($provider), null);
        $transaction = $result['transaction'];
        $this->assertSame('api', $transaction->source->value);
        $this->assertSame('evt-1', $transaction->metadata['provider_event_id']);
        $this->assertSame('100.00', (string) $transaction->calculated_customer_charge);
    }

    public function test_ingestion_persists_only_explicit_adjustment_components_and_preserves_legacy_provider_fee(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $data = $this->data($provider, 'webhook', [
            'provider_fee' => '24.00',
            'provider_fee_components_complete' => true,
            'adjustments' => [
                ['type' => 'provider_fee', 'amount' => '24.00', 'direction' => 'debit', 'source' => 'provider', 'provider_component_code' => 'pos_fee'],
                ['type' => 'vat_tax', 'amount' => '1.20', 'direction' => 'debit', 'source' => 'provider', 'provider_component_code' => 'vat'],
                ['type' => 'levy', 'amount' => '0.80', 'direction' => 'debit', 'source' => 'provider', 'provider_component_code' => 'levy'],
            ],
        ]);

        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $transaction = $ingestion->ingest($agent, $data)['transaction'];
        $duplicate = $ingestion->ingest($agent, $data);

        $this->assertSame('24.00', (string) $transaction->provider_fee);
        $this->assertTrue($transaction->provider_fee_components_complete);
        $this->assertCount(3, $transaction->adjustments()->get());
        $this->assertSame('duplicate', $duplicate['status']);
        $this->assertSame(3, $transaction->adjustments()->count());
        $this->assertSame('26.00', (new EarningsService)->totalProviderFees($agent));
    }

    public function test_normalization_rejects_provider_components_from_unverified_manual_sources(): void
    {
        [, $provider] = $this->setupAgent();

        $this->expectException(InvalidArgumentException::class);
        $this->data($provider, 'manual', ['adjustments' => [
            ['type' => 'vat_tax', 'amount' => '1.00', 'direction' => 'debit', 'source' => 'provider'],
        ]]);
    }

    public function test_normalization_rejects_adjustment_amounts_with_fractional_cents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NormalizedTransactionAdjustmentData::fromArray([
            'type' => 'vat_tax',
            'amount' => '1.001',
            'direction' => 'debit',
            'source' => 'calculated',
            'calculation_rule' => 'pospilot.vat_rule_v1',
        ]);
    }

    public function test_calculated_components_require_a_documented_rule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NormalizedTransactionAdjustmentData::fromArray([
            'type' => 'vat_tax',
            'amount' => '1.00',
            'direction' => 'debit',
            'source' => 'calculated',
        ]);
    }

    public function test_duplicate_ingestion_does_not_overwrite_manual_override(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $first = $ingestion->ingest($agent, $this->data($provider), null)['transaction'];
        $first->update(['customer_charge_override' => '125.00', 'customer_charge' => '125.00', 'customer_charge_source' => 'manual']);
        $ingestion->ingest($agent, $this->data($provider, TransactionSource::Webhook->value), null);
        $first->refresh();
        $this->assertSame('125.00', (string) $first->customer_charge);
        $this->assertSame('manual', $first->customer_charge_source->value);
        $this->assertSame(1, Transaction::count());
    }

    public function test_sync_service_is_idempotent_and_updates_connection_status(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $connection = ProviderConnection::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'connection_type' => 'api', 'connection_status' => 'active']);
        $data = $this->data($provider);
        $connector = new class($data) implements PaymentProviderConnector
        {
            public function __construct(private NormalizedTransactionData $data) {}

            public function transactions(ProviderConnection $connection, ?CarbonImmutable $since = null): iterable
            {
                return [$this->data];
            }

            public function settlements(ProviderConnection $connection, ?CarbonImmutable $since = null): iterable
            {
                return [];
            }
        };
        $sync = new SyncProviderTransactions(new TransactionIngestionService(new ChargeCalculationService));
        $first = $sync->handle($connection, $connector);
        $second = $sync->handle($connection->fresh(), $connector);
        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, $second['duplicates']);
        $this->assertSame('completed', $connection->fresh()->last_sync_status);
        $this->assertSame(1, Transaction::count());
    }
}
