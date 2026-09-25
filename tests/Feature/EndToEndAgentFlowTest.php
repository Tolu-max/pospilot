<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EndToEndAgentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_complete_the_three_provider_flow_through_daily_closing(): void
    {
        $this->post('/register', ['name' => 'Demo Agent', 'email' => 'agent@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect('/dashboard');
        $user = User::where('email', 'agent@example.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user)->patchJson('/api/agent/profile', ['business_name' => 'Lagos Corner POS', 'phone' => '08000000000', 'country' => 'Nigeria', 'currency' => 'NGN', 'onboarding_state' => 'completed'])->assertOk();
        $providers = collect(['OPay' => 'opay', 'Moniepoint' => 'moniepoint', 'PalmPay' => 'palmpay'])->mapWithKeys(fn (string $slug, string $name) => [$slug => Provider::create(['name' => $name, 'slug' => $slug, 'status' => 'active'])]);
        $providerResponse = $this->actingAs($user)->getJson('/api/providers')->assertOk();
        $this->assertSame('supported', $providerResponse->json('data.0.capabilities.csv_transaction_import'));
        $terminals = [];
        foreach ($providers as $slug => $provider) {
            $terminals[$slug] = $this->actingAs($user)->postJson('/api/terminals', ['provider_id' => $provider->id, 'name' => strtoupper($slug).' Main'])->assertCreated()->json('id');
        }
        $this->actingAs($user)->postJson('/api/charge-rules', ['minimum_amount' => '1', 'maximum_amount' => '100000', 'charge_type' => 'fixed', 'charge_value' => '100', 'priority' => 1])->assertCreated();
        $date = CarbonImmutable::today()->toDateString();
        $imports = [['opay', "Transaction ID,Amount,Fee,Status,Transaction Time,Terminal ID,Type\nOP-001,1000,10,Successful,{$date} 09:00:00,OP-TERM,Withdrawal\n"], ['moniepoint', "Transaction Reference,Transaction Amount,Commission,Transaction Status,Transaction Date,Terminal ID,Transaction Type\nMO-001,2000,20,completed,{$date} 10:00:00,MO-TERM,Withdrawal\n"], ['palmpay', "Transaction ID,Order Amount,Handling Fee,Result,Transaction Time,Device ID,Type\nPA-001,3000,30,paid,{$date} 11:00:00,PA-TERM,Withdrawal\n"]];
        foreach ($imports as [$slug, $csv]) {
            $provider = $providers[$slug];
            $preview = $this->actingAs($user)->post('/transactions/import/preview', ['provider_id' => $provider->id, 'file' => UploadedFile::fake()->createWithContent($slug.'.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
            $token = $preview->json('preview_token');
            $this->actingAs($user)->postJson('/transactions/import/confirm', ['preview_token' => $token])->assertOk()->assertJsonPath('rows_imported', 1);
        }
        $this->actingAs($user)->getJson('/api/financial-summary')->assertOk()->assertJsonPath('successful_transaction_count', 3)->assertJsonPath('customer_charges_collected', '300.00')->assertJsonPath('provider_fees', '60.00');
        $settlements = [['opay', "Settlement ID,Settlement Date,Expected Settlement,Actual Settlement,Terminal ID\nSET-OP-001,{$date},990,990,OP-TERM\n"], ['moniepoint', "Settlement Reference,Settlement Date,Expected Settlement,Actual Settlement,Terminal\nSET-MO-001,{$date},1980,1970,MO-TERM\n"], ['palmpay', "Settlement ID,Settlement Date,Expected Settlement,Actual Settlement,Terminal ID\nSET-PA-001,{$date},2970,2970,PA-TERM\n"]];
        foreach ($settlements as [$slug, $csv]) {
            $provider = $providers[$slug];
            $preview = $this->actingAs($user)->post('/settlements/import/preview', ['provider_id' => $provider->id, 'file' => UploadedFile::fake()->createWithContent($slug.'-settlements.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
            $this->actingAs($user)->postJson('/settlements/import/confirm', ['preview_token' => $preview->json('preview_token')])->assertOk()->assertJsonPath('rows_imported', 1);
        }
        $this->actingAs($user)->getJson('/api/reconciliation/overview')->assertOk()->assertJsonPath('settlement_count', 3)->assertJsonPath('outcomes.partially_reconciled', 1);
        $this->actingAs($user)->postJson('/api/expenses', ['amount' => '50.00', 'category' => 'power', 'description' => 'Generator fuel', 'expense_date' => $date])->assertCreated();
        $this->actingAs($user)->getJson('/api/financial-summary')->assertJsonPath('expenses', '50.00')->assertJsonPath('estimated_net_earnings', '190.00');
        $draft = $this->actingAs($user)->postJson('/api/daily-closings', ['closing_date' => $date])->assertCreated();
        $closingId = $draft->json('id');
        foreach (['opay' => 990, 'moniepoint' => 1980, 'palmpay' => 2970] as $slug => $balance) {
            $this->actingAs($user)->postJson('/api/daily-closings/'.$closingId.'/balances', ['provider_id' => $providers[$slug]->id, 'actual_balance' => (string) $balance])->assertOk();
        } $this->actingAs($user)->getJson('/api/daily-closings/'.$closingId)->assertOk()->assertJsonPath('total_variance', '0.00');
        $this->actingAs($user)->postJson('/api/daily-closings/'.$closingId.'/finalize')->assertOk()->assertJsonPath('status', 'finalized');

        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('settlements', 3);
    }
}
