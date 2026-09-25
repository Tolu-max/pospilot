<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class IntegrationQaScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_fictional_three_provider_qa_scenario_matches_financial_contract(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 18:00:00');
        $this->getJson('/api/financial-summary')->assertUnauthorized();
        $this->post('/register', [
            'name' => 'QA POS Agent',
            'email' => 'qa-agent@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'qa-agent@example.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user);
        $this->patchJson('/api/agent/profile', [
            'business_name' => 'Fictional Oja Corner POS',
            'country' => 'Nigeria',
            'currency' => 'NGN',
            'onboarding_state' => 'completed',
        ])->assertOk()->assertJsonPath('business_name', 'Fictional Oja Corner POS');

        $providers = collect(['opay', 'moniepoint', 'palmpay'])->mapWithKeys(fn (string $slug) => [
            $slug => Provider::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active']),
        ]);
        $this->getJson('/api/providers')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.capabilities.csv_transaction_import', 'supported');
        $terminalIdentifiers = ['opay' => 'OPA-QA-01', 'moniepoint' => 'MP-QA-01', 'palmpay' => 'PPA-QA-01'];
        foreach ($providers as $slug => $provider) {
            $this->postJson('/api/terminals', [
                'provider_id' => $provider->id,
                'name' => strtoupper($slug).' QA Terminal',
                'terminal_identifier' => $terminalIdentifiers[$slug],
            ])->assertCreated();
        }

        foreach ([
            ['minimum_amount' => '1.00', 'maximum_amount' => '40000.00', 'charge_value' => '500.00'],
            ['minimum_amount' => '40000.01', 'maximum_amount' => '49999.99', 'charge_value' => '600.00'],
            ['minimum_amount' => '50000.00', 'maximum_amount' => null, 'charge_value' => '700.00'],
        ] as $range) {
            $this->postJson('/api/charge-rules', $range + ['charge_type' => 'fixed', 'priority' => 1, 'active' => true])->assertCreated();
        }
        $this->getJson('/api/charge-rules/preview?amount=50000.00')->assertOk()->assertJsonPath('charge', '700.00');

        $transactionBatches = [];
        foreach ($providers as $slug => $provider) {
            $file = UploadedFile::fake()->createWithContent($slug.'-transactions.csv', file_get_contents(base_path('docs/qa/scenario/'.$slug.'-transactions.csv')));
            $preview = $this->post('/transactions/import/preview', ['provider_id' => $provider->id, 'file' => $file], ['Accept' => 'application/json'])->assertOk();
            $this->assertSame(0, $preview->json('invalid'));
            $transactionBatches[$slug] = $this->postJson('/transactions/import/confirm', ['preview_token' => $preview->json('preview_token')])->assertOk()->json();
        }
        $this->assertSame(2, $transactionBatches['opay']['rows_imported']);
        $this->assertSame(1, $transactionBatches['moniepoint']['rows_imported']);
        $this->assertSame(3, $transactionBatches['palmpay']['rows_imported']);

        $duplicateFile = UploadedFile::fake()->createWithContent('opay-repeat.csv', file_get_contents(base_path('docs/qa/scenario/opay-transactions.csv')));
        $duplicatePreview = $this->post('/transactions/import/preview', ['provider_id' => $providers['opay']->id, 'file' => $duplicateFile], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2, $duplicatePreview->json('duplicates'));
        $this->postJson('/transactions/import/confirm', ['preview_token' => $duplicatePreview->json('preview_token')])->assertOk()->assertJsonPath('rows_imported', 0)->assertJsonPath('rows_duplicate', 2);

        $transactions = $this->getJson('/api/transactions?from=2026-06-01&to=2026-06-01&per_page=100')->assertOk();
        $this->assertSame(6, $transactions->json('total'));
        $pending = collect($transactions->json('data'))->firstWhere('external_reference', 'QA-OP-002');
        $this->assertNotNull($pending);
        $this->assertSame($terminalIdentifiers['opay'], $pending['terminal']['terminal_identifier']);
        $this->patchJson('/api/transactions/'.$pending['id'].'/customer-charge', ['customer_charge_override' => '550.00'])->assertOk()->assertJsonPath('customer_charge_override', '550.00');
        $this->getJson('/api/transactions/'.$pending['id'])->assertOk()->assertJsonPath('customer_charge_source', 'manual');

        $this->postJson('/api/expenses', ['amount' => '800.00', 'category' => 'transport', 'description' => 'Fictional daily fuel', 'expense_date' => '2026-06-01'])->assertCreated();
        foreach ($providers as $slug => $provider) {
            $file = UploadedFile::fake()->createWithContent($slug.'-settlements.csv', file_get_contents(base_path('docs/qa/scenario/'.$slug.'-settlements.csv')));
            $preview = $this->post('/settlements/import/preview', ['provider_id' => $provider->id, 'file' => $file], ['Accept' => 'application/json'])->assertOk();
            $this->assertSame(0, $preview->json('invalid'));
            $this->postJson('/settlements/import/confirm', ['preview_token' => $preview->json('preview_token')])->assertOk()->assertJsonPath('rows_imported', 1);
        }

        $this->getJson('/api/reconciliation/overview?from=2026-06-01&to=2026-06-01')->assertOk()->assertJsonPath('settlement_count', 3)->assertJsonPath('outcomes.partially_reconciled', 1)->assertJsonPath('unreconciled_amount', '-50.00');
        $summary = $this->getJson('/api/financial-summary?from=2026-06-01&to=2026-06-01')->assertOk();
        $summary->assertJsonPath('transaction_volume', '185000.00')
            ->assertJsonPath('successful_transaction_count', 4)
            ->assertJsonPath('customer_charges_collected', '2500.00')
            ->assertJsonPath('provider_fees', '620.00')
            ->assertJsonPath('expenses', '800.00')
            ->assertJsonPath('estimated_net_earnings', '1080.00')
            ->assertJsonPath('earnings_status', 'provisional')
            ->assertJsonPath('provisional_reasons.0.code', 'provider_fee_components_incomplete')
            ->assertJsonPath('provisional_reasons.0.transaction_count', 4)
            ->assertJsonPath('pending_transaction_value', '20000.00')
            ->assertJsonPath('reversed_transaction_value', '15000.00');
        $this->getJson('/api/provider-breakdown?from=2026-06-01&to=2026-06-01')->assertOk()->assertJsonCount(3, 'data');

        $draft = $this->postJson('/api/daily-closings', ['closing_date' => '2026-06-01', 'opening_cash' => '200000.00', 'entered_closing_cash' => '16690.00'])->assertCreated();
        $closingId = $draft->json('id');
        $this->assertSame('16700.00', $draft->json('expected_cash'));
        $this->postJson('/api/daily-closings/'.$closingId.'/finalize')->assertUnprocessable();
        foreach (['opay' => '49800.00', 'moniepoint' => '44840.00', 'palmpay' => '89690.00'] as $slug => $balance) {
            $this->postJson('/api/daily-closings/'.$closingId.'/balances', ['provider_id' => $providers[$slug]->id, 'actual_balance' => $balance])->assertOk();
        }
        $this->getJson('/api/daily-closings/'.$closingId)->assertOk()
            ->assertJsonPath('expected_electronic_position', '184380.00')
            ->assertJsonPath('actual_electronic_position', '184330.00')
            ->assertJsonPath('total_variance', '-60.00')
            ->assertJsonPath('variance_status', 'small_variance');
        $this->postJson('/api/daily-closings/'.$closingId.'/finalize')->assertOk()->assertJsonPath('status', 'finalized');
        $this->postJson('/api/daily-closings/'.$closingId.'/finalize')->assertUnprocessable();

        CarbonImmutable::setTestNow();
    }

    public function test_empty_and_oversized_transaction_and_settlement_uploads_are_rejected(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create(['slug' => 'opay']);
        $this->actingAs($user);
        $emptyCsv = UploadedFile::fake()->createWithContent('empty-statement.csv', 'Reference,Amount,Date');

        $this->post('/transactions/import/preview', ['provider_id' => $provider->id, 'file' => $emptyCsv], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/settlements/import/preview', ['provider_id' => $provider->id, 'file' => $emptyCsv], ['Accept' => 'application/json'])->assertUnprocessable();

        $oversizedCsv = UploadedFile::fake()->create('oversized.csv', 5121);
        $this->post('/transactions/import/preview', ['provider_id' => $provider->id, 'file' => $oversizedCsv], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/settlements/import/preview', ['provider_id' => $provider->id, 'file' => $oversizedCsv], ['Accept' => 'application/json'])->assertUnprocessable();

        $invalidFileType = UploadedFile::fake()->create('not-a-csv.exe', 1, 'application/octet-stream');
        $this->post('/transactions/import/preview', ['provider_id' => $provider->id, 'file' => $invalidFileType], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/settlements/import/preview', ['provider_id' => $provider->id, 'file' => $invalidFileType], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseHas('agent_profiles', ['id' => $agent->id]);
    }

    public function test_invalid_negative_transaction_row_is_reported_without_discarding_valid_rows(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create(['slug' => 'opay']);
        $csv = "Transaction ID,Amount,Fee,Status,Transaction Time\nGOOD-001,1000,10,successful,2026-06-01 09:00:00\nBAD-001,-5,0,successful,2026-06-01 09:01:00\n";
        $file = UploadedFile::fake()->createWithContent('mixed-validity.csv', $csv);
        $this->actingAs($user);

        $preview = $this->post('/transactions/import/preview', ['provider_id' => $provider->id, 'file' => $file], ['Accept' => 'application/json'])->assertOk();
        $preview->assertJsonPath('rows_detected', 2)->assertJsonPath('valid', 1)->assertJsonPath('invalid', 1);
        $this->assertStringContainsString('greater than zero', implode(' ', $preview->json('rows.1.errors')));
        $this->postJson('/transactions/import/confirm', ['preview_token' => $preview->json('preview_token')])->assertOk()->assertJsonPath('rows_imported', 1)->assertJsonPath('rows_failed', 1);
        $this->assertDatabaseCount('transactions', 1);
    }
}
