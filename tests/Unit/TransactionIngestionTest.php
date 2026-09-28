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

    public function test_statement_fee_enriches_a_matching_api_record_with_source_provenance(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $apiData = $this->data($provider, 'api', ['provider_fee' => '0.00', 'provider_fee_supplied' => false]);
        $original = $ingestion->ingest($agent, $apiData)['transaction'];

        $statementData = $this->data($provider, 'statement', ['provider_fee' => '24.00', 'provider_fee_supplied' => true]);
        $enriched = $ingestion->ingest($agent, $statementData);

        $this->assertSame('duplicate', $enriched['status']);
        $this->assertSame($original->id, $enriched['transaction']->id);
        $this->assertSame('24.00', (string) $enriched['transaction']->fresh()->provider_fee);
        $this->assertTrue($enriched['transaction']->fresh()->provider_fee_supplied);
        $this->assertSame('provider_statement', $enriched['transaction']->fresh()->metadata['provider_fee_provenance']);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(2, $enriched['transaction']->sourceRecords()->count());
        $this->assertDatabaseHas('transaction_source_records', ['transaction_id' => $original->id, 'source_type' => 'provider_api']);
        $this->assertDatabaseHas('transaction_source_records', ['transaction_id' => $original->id, 'source_type' => 'provider_statement']);
    }

    public function test_higher_priority_api_fee_replaces_statement_fee_and_keeps_audit_history(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $statement = $this->data($provider, 'statement', ['provider_fee' => '30.00', 'provider_fee_supplied' => true]);
        $transaction = $ingestion->ingest($agent, $statement)['transaction'];
        $api = $this->data($provider, 'api', ['provider_fee' => '24.00', 'provider_fee_supplied' => true]);

        $result = $ingestion->ingest($agent, $api);
        $transaction->refresh();

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame('24.00', (string) $transaction->provider_fee);
        $this->assertSame('provider_api', $transaction->metadata['provider_fee_provenance']);
        $this->assertSame('30.00', $transaction->metadata['provider_fee_history'][0]['amount']);
        $this->assertSame('provider_statement', $transaction->metadata['provider_fee_history'][0]['source']);
    }

    public function test_rrn_can_deduplicate_api_and_statement_records_with_different_display_references(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $api = $this->data($provider, 'api', ['external_reference' => 'OPAY-PAY-1', 'metadata' => ['rrn' => 'RRN-1']]);
        $statement = $this->data($provider, 'statement', ['external_reference' => 'STATEMENT-ROW-1', 'metadata' => ['rrn' => 'RRN-1']]);
        $original = $ingestion->ingest($agent, $api)['transaction'];

        $duplicate = $ingestion->ingest($agent, $statement);

        $this->assertSame('duplicate', $duplicate['status']);
        $this->assertSame($original->id, $duplicate['transaction']->id);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(2, $duplicate['transaction']->sourceRecords()->count());
    }

    public function test_source_references_are_hashed_and_repeat_sync_reuses_the_source_record(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $data = $this->data($provider, 'api', [
            'external_reference' => 'PRIVATE-PROVIDER-REFERENCE-78231',
            'metadata' => ['source_reference' => 'PRIVATE-SOURCE-EVENT-78231', 'rrn' => 'RRN-PRIVATE-78231'],
        ]);
        $transaction = $ingestion->ingest($agent, $data)['transaction'];

        $ingestion->ingest($agent, $data);
        $sourceRecord = $transaction->sourceRecords()->sole();

        $this->assertNotSame('PRIVATE-SOURCE-EVENT-78231', $sourceRecord->source_reference_fingerprint);
        $this->assertNotSame('RRN-PRIVATE-78231', $sourceRecord->metadata_fingerprint);
        $this->assertSame('provider_api', $sourceRecord->source_type);
        $this->assertSame(1, $transaction->sourceRecords()->count());
    }

    public function test_fallback_fingerprint_ignores_fee_but_scopes_by_business_and_terminal(): void
    {
        [, $provider] = $this->setupAgent();
        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $first = $this->data($provider, 'api', [
            'external_reference' => null,
            'terminal_identifier' => 'TERM-1',
            'provider_fee' => '0.00',
            'provider_fee_supplied' => false,
            'metadata' => ['business_id' => 'BUSINESS-1'],
        ]);
        $sameTransactionWithFee = $this->data($provider, 'statement', [
            'external_reference' => null,
            'terminal_identifier' => 'TERM-1',
            'provider_fee' => '24.00',
            'provider_fee_supplied' => true,
            'metadata' => ['business_id' => 'BUSINESS-1'],
        ]);
        $differentTerminal = $this->data($provider, 'api', [
            'external_reference' => null,
            'terminal_identifier' => 'TERM-2',
            'metadata' => ['business_id' => 'BUSINESS-1'],
        ]);

        $this->assertSame($ingestion->fingerprint($first), $ingestion->fingerprint($sameTransactionWithFee));
        $this->assertNotSame($ingestion->fingerprint($first), $ingestion->fingerprint($differentTerminal));
    }

    public function test_fallback_identity_without_terminal_or_account_context_does_not_merge_sources(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $ingestion = new TransactionIngestionService(new ChargeCalculationService);
        $api = $this->data($provider, 'api', [
            'external_reference' => null,
            'terminal_identifier' => null,
            'metadata' => ['source_reference' => 'api-observation-1'],
        ]);
        $statement = $this->data($provider, 'statement', [
            'external_reference' => null,
            'terminal_identifier' => null,
            'metadata' => ['source_reference' => 'statement-attachment-1'],
        ]);

        $this->assertNotSame($ingestion->fingerprint($api), $ingestion->fingerprint($statement));
        $this->assertSame('imported', $ingestion->ingest($agent, $api)['status']);
        $this->assertSame('imported', $ingestion->ingest($agent, $statement)['status']);
        $this->assertSame(2, Transaction::count());
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
