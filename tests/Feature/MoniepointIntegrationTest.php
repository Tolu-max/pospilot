<?php

namespace Tests\Feature;

use App\Contracts\ProviderSecretStore;
use App\Enums\ProviderConnectionStatus;
use App\Exceptions\ProviderSecretAccessDenied;
use App\Jobs\SyncMoniepointTransactions;
use App\Models\AgentProfile;
use App\Models\Provider;
use App\Models\ProviderConnection;
use App\Models\ProviderWebhookReceipt;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MoniepointIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_credentials_are_encrypted_hidden_and_tested_against_documented_read_only_endpoint(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'moniepoint', 'name' => 'Moniepoint']);
        Http::fake(['https://posapi.development.moniepoint.com/v1/introspect' => Http::response([
            'scopes' => ['webhook:read'],
            'businesses' => [['id' => 43210, 'businessName' => 'Fictional Corner Shop']],
            'authMethod' => 'API_KEY',
        ])]);

        $saved = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->putJson('/api/providers/moniepoint/connection', [
            'api_key' => 'fictional-api-key-not-real',
            'webhook_secret' => 'fictional-webhook-secret-not-real',
            'business_id' => '43210',
        ])->assertCreated()->assertJsonPath('connected', false);
        $saved->assertDontSee('fictional-api-key-not-real')->assertDontSee('fictional-webhook-secret-not-real');

        $connection = ProviderConnection::query()->firstOrFail();
        $this->assertSame([
            'api_key' => 'fictional-api-key-not-real',
            'webhook_secret' => 'fictional-webhook-secret-not-real',
        ], app(ProviderSecretStore::class)->retrieve($connection));
        $this->assertArrayNotHasKey('secret_reference', $connection->toArray());
        $this->assertDatabaseMissing('provider_connections', ['id' => $connection->id, 'secret_reference' => 'fictional-api-key-not-real']);
        $this->assertDatabaseMissing('provider_secret_records', ['secret_reference' => $connection->secret_reference, 'secrets' => 'fictional-api-key-not-real']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/providers/moniepoint/connection/test')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('status', 'verified')
            ->assertJsonMissingPath('connection.credentials');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://posapi.development.moniepoint.com/v1/introspect'
            && $request->hasHeader('Authorization', 'Bearer fictional-api-key-not-real'));
    }

    public function test_failed_connection_test_keeps_error_and_response_free_of_secret_values(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'moniepoint']);
        Http::fake(['https://posapi.development.moniepoint.com/v1/introspect' => Http::response(['message' => 'do not echo credentials'], 401)]);
        Log::spy();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->putJson('/api/providers/moniepoint/connection', [
            'api_key' => 'fictional-secret-error-check',
            'webhook_secret' => 'fictional-webhook-secret',
            'business_id' => '43210',
        ])->assertCreated();
        $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/providers/moniepoint/connection/test')->assertUnprocessable();
        $response->assertDontSee('fictional-secret-error-check')->assertDontSee('do not echo credentials');

        $connection = ProviderConnection::query()->firstOrFail();
        $this->assertSame(ProviderConnectionStatus::Error, $connection->connection_status);
        $this->assertStringNotContainsString('fictional-secret-error-check', $connection->last_sync_error);
        Log::shouldHaveReceived('warning')->with('provider_connection_event', \Mockery::on(function (array $context): bool {
            return ! in_array('fictional-secret-error-check', $context, true)
                && ! in_array('fictional-webhook-secret', $context, true);
        }))->once();
    }

    public function test_signed_moniepoint_webhook_normalizes_minimal_data_maps_terminal_and_is_idempotent(): void
    {
        [$user, $agent, $provider, $connection] = $this->connectedAgent();
        Terminal::factory()->create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'terminal_identifier' => 'P260FICTIONAL01',
        ]);
        $payload = [
            'eventId' => 'event-body-1',
            'eventType' => 'V1_POS_TRANSFER_TRANSACTION',
            'subject' => ['domain' => 'CHANNELS', 'resource' => 'business', 'resourceId' => '43210'],
            'data' => [
                'businessId' => 43210,
                'businessOwnerId' => 900,
                'terminalSerial' => 'P260FICTIONAL01',
                'amount' => 125000,
                'transactionReference' => 'MP-REF-0001',
                'transactionTime' => '2026-09-24T10:15:30+01:00',
                'transactionType' => 'POS_TRANSFER',
                'transactionStatus' => 'APPROVED',
                'responseCode' => '00',
                'customerName' => 'Fictional Customer',
                'customerPhone' => '08000000000',
                'customerAccountNumber' => '1234567890',
            ],
            'createdAt' => '2026-09-24T10:15:31+01:00',
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $eventId = 'fictional-header-event-1';
        $timestamp = (string) now()->getTimestampMs();
        $signature = base64_encode(hash_hmac('sha256', implode('__', [$eventId, $timestamp, $rawBody]), 'fictional-webhook-secret', true));

        $this->postSignedWebhook($eventId, $timestamp, $signature, $rawBody)->assertOk()->assertJsonPath('accepted', true)->assertJsonPath('duplicate', false);
        $this->postSignedWebhook($eventId, $timestamp, $signature, $rawBody)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, ProviderWebhookReceipt::query()->count());
        $transaction = Transaction::query()->firstOrFail();
        $this->assertSame('1250.00', $transaction->amount);
        $this->assertSame('0.00', $transaction->provider_fee);
        $this->assertSame('successful', $transaction->transaction_status->value);
        $this->assertSame('transfer', $transaction->transaction_type);
        $this->assertSame('P260FICTIONAL01', $transaction->terminal->terminal_identifier);
        $this->assertSame('fictional-header-event-1', $transaction->metadata['provider_event_id']);
        $this->assertFalse($transaction->metadata['provider_fee_supplied']);
        $this->assertArrayNotHasKey('customerName', $transaction->metadata);
        $this->assertArrayNotHasKey('customerPhone', $transaction->metadata);
        $this->assertArrayNotHasKey('customerAccountNumber', $transaction->metadata);
        $this->assertNotNull($connection->fresh()->last_webhook_at);

        $this->actingAs($user)->getJson('/api/transactions/'.$transaction->id)
            ->assertOk()
            ->assertJsonPath('financial_status.financial_data_status', 'provisional')
            ->assertJsonPath('financial_status.earnings_status', 'provisional')
            ->assertJsonPath('financial_status.reasons.0', 'provider_fee_missing')
            ->assertJsonPath('financial_status.is_final', false);
        $this->actingAs($user)->getJson('/api/financial-summary')
            ->assertOk()
            ->assertJsonPath('earnings_status', 'provisional')
            ->assertJsonPath('financial_data_status', 'provisional')
            ->assertJsonPath('is_final', false)
            ->assertJsonPath('provisional_reasons.0.code', 'provider_fee_missing');
    }

    public function test_unsigned_or_invalid_signature_moniepoint_webhook_is_rejected_and_logged_without_payload(): void
    {
        $this->connectedAgent();
        Log::spy();

        $response = $this->postJson('/webhooks/providers/moniepoint', [
            'data' => ['businessId' => 43210, 'customerName' => 'Do not log this'],
        ])->assertUnauthorized()->assertJsonPath('message', 'Webhook rejected.');
        $response->assertDontSee('Do not log this');

        $this->assertSame(0, Transaction::query()->count());
        Log::shouldHaveReceived('warning')->with('provider_webhook_rejected', [
            'provider' => 'moniepoint',
            'reason' => 'signature_or_connection_invalid',
        ])->once();
    }

    public function test_moniepoint_connection_endpoints_are_scoped_and_disconnect_preserves_history(): void
    {
        [$owner, $agent, $provider, $connection] = $this->connectedAgent();
        $transaction = Transaction::factory()->create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'source' => 'webhook',
        ]);
        $other = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $other->id]);

        $this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/providers/moniepoint/connection/test')->assertNotFound();
        $this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])->getJson('/api/providers/moniepoint/connection')->assertOk()->assertJsonPath('connected', false);
        $this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])->deleteJson('/api/providers/moniepoint/connection')->assertOk();
        $this->assertSame(ProviderConnectionStatus::Active, $connection->fresh()->connection_status);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->deleteJson('/api/providers/moniepoint/connection')->assertOk()->assertJsonPath('status', 'disconnected');
        $connection->refresh();
        $this->assertSame(ProviderConnectionStatus::Inactive, $connection->connection_status);
        $this->assertNull($connection->secret_reference);
        try {
            app(ProviderSecretStore::class)->retrieve($connection);
            $this->fail('Disconnected provider credentials must no longer be accessible.');
        } catch (ProviderSecretAccessDenied) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        (new SyncMoniepointTransactions($connection->id))->handle(app(TransactionIngestionService::class));
        $this->assertSame('disconnected', $connection->fresh()->last_sync_status);
        $this->postJson('/webhooks/providers/moniepoint', ['data' => ['businessId' => 43210]])->assertUnauthorized();
    }

    public function test_provider_capabilities_report_only_the_implemented_moniepoint_webhook_path_as_supported(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['name' => 'Moniepoint', 'slug' => 'moniepoint']);

        $providers = $this->actingAs($user)->getJson('/api/providers')->assertOk()->json('data');
        $moniepoint = collect($providers)->firstWhere('slug', 'moniepoint');
        $this->assertSame('planned', $moniepoint['capabilities']['webhook_transactions']);
        $this->assertSame('planned', $moniepoint['capabilities']['api_transaction_sync']);
    }

    public function test_backfill_job_stops_with_a_documentation_status_without_a_history_connector(): void
    {
        [, , , $connection] = $this->connectedAgent();
        Http::fake();

        (new SyncMoniepointTransactions($connection->id))->handle(app(TransactionIngestionService::class));

        $connection->refresh();
        $this->assertSame('blocked_documentation', $connection->last_sync_status);
        $this->assertSame('Moniepoint transaction history API is not configured.', $connection->last_sync_error);
        $this->assertNull($connection->last_synced_at);
        Http::assertNothingSent();
    }

    public function test_webhook_resolves_several_moniepoint_terminal_serials_for_one_business(): void
    {
        [, $agent, $provider] = $this->connectedAgent();
        foreach (['P260FICTIONAL01', 'P260FICTIONAL02'] as $serial) {
            Terminal::factory()->create([
                'agent_profile_id' => $agent->id,
                'provider_id' => $provider->id,
                'terminal_identifier' => $serial,
            ]);
        }

        foreach (['P260FICTIONAL01', 'P260FICTIONAL02'] as $index => $serial) {
            $payload = [
                'eventId' => 'body-event-'.$index,
                'eventType' => 'V1_POS_WITHDRAWAL_TRANSACTION',
                'data' => [
                    'businessId' => 43210,
                    'terminalSerial' => $serial,
                    'amount' => 100000,
                    'transactionReference' => 'MP-WITHDRAW-'.$index,
                    'transactionTime' => '2026-09-24T11:00:00+01:00',
                    'transactionType' => 'POS_WITHDRAWAL',
                    'transactionStatus' => 'COMPLETED',
                ],
            ];
            $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
            $eventId = 'fictional-terminal-event-'.$index;
            $timestamp = (string) now()->getTimestampMs();
            $signature = base64_encode(hash_hmac('sha256', implode('__', [$eventId, $timestamp, $rawBody]), 'fictional-webhook-secret', true));

            $this->postSignedWebhook($eventId, $timestamp, $signature, $rawBody)->assertOk();
        }

        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(
            ['P260FICTIONAL01', 'P260FICTIONAL02'],
            Transaction::query()->with('terminal')->orderBy('id')->get()->map(fn (Transaction $transaction): ?string => $transaction->terminal?->terminal_identifier)->all(),
        );
    }

    /** @return array{User, AgentProfile, Provider, ProviderConnection} */
    private function connectedAgent(): array
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create(['name' => 'Moniepoint', 'slug' => 'moniepoint']);
        $connection = ProviderConnection::query()->create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'connection_type' => 'api',
            'connection_status' => 'active',
            'provider_merchant_identifier' => '43210',
        ]);
        app(ProviderSecretStore::class)->store($connection, [
            'api_key' => 'fictional-api-key',
            'webhook_secret' => 'fictional-webhook-secret',
        ]);
        $connection->refresh();

        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $agent, $provider, $connection];
    }

    private function postSignedWebhook(string $eventId, string $timestamp, string $signature, string $rawBody): TestResponse
    {
        return $this->call('POST', '/webhooks/providers/moniepoint', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MONIEPOINT_WEBHOOK_ID' => $eventId,
            'HTTP_MONIEPOINT_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_MONIEPOINT_WEBHOOK_SIGNATURE' => $signature,
        ], $rawBody);
    }
}
