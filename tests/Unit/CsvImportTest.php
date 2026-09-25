<?php

namespace Tests\Unit;

use App\Enums\ChargeType;
use App\Enums\CustomerChargeSource;
use App\Imports\CsvImporterFactory;
use App\Models\AgentProfile;
use App\Models\ChargeRule;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ChargeCalculationService;
use App\Services\CsvImportService;
use App\Services\EarningsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function setupAgent(string $slug = 'opay'): array
    {
        $agent = AgentProfile::create(['user_id' => User::factory()->create()->id, 'business_name' => 'Import Test Agent']);
        $provider = Provider::create(['name' => strtoupper($slug), 'slug' => $slug]);
        ChargeRule::create(['agent_profile_id' => $agent->id, 'minimum_amount' => 1, 'maximum_amount' => null, 'charge_type' => ChargeType::Fixed->value, 'charge_value' => 100, 'active' => true]);

        return [$agent, $provider];
    }

    public function test_opay_adapter_normalizes_provider_columns(): void
    {
        $importer = (new CsvImporterFactory)->make(Provider::make(['slug' => 'opay']));
        $result = $importer->normalize(['Transaction ID', 'Amount', 'Service Charge', 'Fee', 'Status', 'Transaction Time', 'Terminal ID'], ['OP-1', '10000', '200', '50', 'Successful', '2026-09-22 10:00:00', 'TERM-1'], 2);
        $this->assertTrue($result['valid']);
        $this->assertSame('10000.00', $result['normalized']['amount']);
        $this->assertSame('200.00', $result['normalized']['customer_charge']);
        $this->assertSame('successful', $result['normalized']['transaction_status']);
    }

    public function test_moniepoint_and_palmpay_adapters_use_isolated_mappings(): void
    {
        $factory = new CsvImporterFactory;
        $monie = $factory->make(Provider::make(['slug' => 'moniepoint']))->normalize(['Transaction Reference', 'Transaction Amount', 'Commission', 'Transaction Status', 'Transaction Date'], ['M-1', '15000', '45', 'completed', '2026-09-22 10:00:00'], 2);
        $palm = $factory->make(Provider::make(['slug' => 'palmpay']))->normalize(['Transaction ID', 'Order Amount', 'Handling Fee', 'Result', 'Transaction Time'], ['P-1', '12500', '25', 'paid', '2026-09-22 10:00:00'], 2);
        $this->assertTrue($monie['valid']);
        $this->assertSame('45.00', $monie['normalized']['provider_fee']);
        $this->assertSame('successful', $monie['normalized']['transaction_status']);
        $this->assertTrue($palm['valid']);
        $this->assertSame('12500.00', $palm['normalized']['amount']);
        $this->assertSame('successful', $palm['normalized']['transaction_status']);
    }

    public function test_generic_mapping_and_charge_rule_fallback_work(): void
    {
        [$agent, $provider] = $this->setupAgent('other');
        $service = new CsvImportService(new CsvImporterFactory, new ChargeCalculationService);
        $file = UploadedFile::fake()->createWithContent('generic.csv', "Reference,Transaction Amount,Provider Fee,Date/Time\nGEN-1,8000,24,2026-09-22 10:00:00\n");
        $preview = $service->preview($file, $agent, $provider, ['external_reference' => 'Reference', 'amount' => 'Transaction Amount', 'provider_fee' => 'Provider Fee', 'transaction_at' => 'Date/Time']);
        $result = $service->importPreview($agent, $provider, 'generic.csv', $preview);
        $transaction = Transaction::first();
        $this->assertSame(1, $result['rows_imported']);
        $this->assertSame('100.00', (string) $transaction->calculated_customer_charge);
        $this->assertSame(CustomerChargeSource::Calculated, $transaction->customer_charge_source);
    }

    public function test_imported_charge_is_preserved_as_a_distinct_source(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $service = new CsvImportService(new CsvImporterFactory, new ChargeCalculationService);
        $file = UploadedFile::fake()->createWithContent('opay.csv', "Reference,Amount,Service Charge,Date\nOP-2,8000,175,2026-09-22 10:00:00\n");
        $preview = $service->preview($file, $agent, $provider);
        $service->importPreview($agent, $provider, 'opay.csv', $preview);
        $transaction = Transaction::first();
        $this->assertSame('175.00', (string) $transaction->customer_charge);
        $this->assertSame('175.00', (string) $transaction->imported_customer_charge);
        $this->assertSame(CustomerChargeSource::Imported, $transaction->customer_charge_source);
    }

    public function test_same_statement_is_idempotent_but_same_amount_and_time_with_different_references_is_not(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $service = new CsvImportService(new CsvImporterFactory, new ChargeCalculationService);
        $csv = "Reference,Amount,Date\nOP-A,1000,2026-09-22 10:00:00\nOP-B,1000,2026-09-22 10:00:00\n";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);
        $preview = $service->preview($file, $agent, $provider);
        $first = $service->importPreview($agent, $provider, 'statement.csv', $preview);
        $secondPreview = $service->preview($file, $agent, $provider);
        $second = $service->importPreview($agent, $provider, 'statement.csv', $secondPreview);
        $this->assertSame(2, $first['rows_imported']);
        $this->assertSame(0, $second['rows_imported']);
        $this->assertSame(2, $second['rows_duplicate']);
        $this->assertSame(2, Transaction::count());
        $this->assertFalse(Transaction::firstOrFail()->provider_fee_supplied);
        $this->assertSame(['provider_fee_missing'], (new EarningsService)->transactionFinancialStatus(Transaction::firstOrFail())['reasons']);
    }

    public function test_invalid_rows_are_reported_and_not_imported(): void
    {
        [$agent, $provider] = $this->setupAgent();
        $service = new CsvImportService(new CsvImporterFactory, new ChargeCalculationService);
        $file = UploadedFile::fake()->createWithContent('bad.csv', "Reference,Amount,Date\nGOOD,1000,2026-09-22 10:00:00\nBAD,not-money,not-a-date\n");
        $preview = $service->preview($file, $agent, $provider);
        $this->assertSame(1, $preview['valid']);
        $this->assertSame(1, $preview['invalid']);
        $result = $service->importPreview($agent, $provider, 'bad.csv', $preview);
        $this->assertSame(1, $result['rows_imported']);
        $this->assertSame(1, $result['rows_failed']);
    }
}
