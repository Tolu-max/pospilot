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
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MoniepointIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['provider_secrets.moniepoint_direct_enabled' => true]);
    }

    public function test_connection_credentials_are_encrypted_hidden_and_tested_against_documented_read_only_endpoint(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'moniepoint', 'name' => 'Moniepoint']);
        Http::fake(['https://api.pos.beta.moniepoint.com/v1/introspect' => Http::response([
            'scopes' => ['webhook:read'],
            'businesses' => [['id' => 43210, 'businessName' => 'Fictional Corner Shop']],
            'authMethod' => 'API_KEY',
            'environment' => 'SANDBOX',
        ])]);

        $saved = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->putJson('/api/providers/moniepoint/connection', [
            'api_key' => 'fictional-api-key-not-real',
            'business_id' => '43210',
        ])->assertCreated()->assertJsonPath('connected', false);
        $saved->assertJsonPath('configured', true)->assertDontSee('fictional-api-key-not-real');

        $connection = ProviderConnection::query()->firstOrFail();
        $this->assertSame(['api_key' => 'fictional-api-key-not-real'], app(ProviderSecretStore::class)->retrieve($connection));
        $this->assertArrayNotHasKey('secret_reference', $connection->toArray());
        $this->assertDatabaseMissing('provider_connections', ['id' => $connection->id, 'secret_reference' => 'fictional-api-key-not-real']);
        $this->assertDatabaseMissing('provider_secret_records', ['secret_reference' => $connection->secret_reference, 'secrets' => 'fictional-api-key-not-real']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/providers/moniepoint/connection/test')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('connection.environment', 'SANDBOX')
            ->assertJsonPath('connection.granted_scopes.0', 'webhook:read')
            ->assertJsonMissingPath('connection.credentials');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.pos.beta.moniepoint.com/v1/introspect'
            && $request->hasHeader('Authorization', 'Bearer fictional-api-key-not-real'));
        $this->assertSame('Fictional Corner Shop', $connection->fresh()->metadata['moniepoint_introspection']['business_name']);
    }

    public function test_failed_connection_test_keeps_error_and_response_free_of_secret_values(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'moniepoint']);
        Http::fake(['https://api.pos.beta.moniepoint.com/v1/introspect' => Http::response(['message' => 'do not echo credentials'], 401)]);
        Log::spy();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->putJson('/api/providers/moniepoint/connection', [
            'api_key' => 'fictional-secret-error-check',
            'business_id' => '43210',
        ])->assertCreated();
        $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/providers/moniepoint/connection/test')->assertUnprocessable();
        $response->assertDontSee('fictional-secret-error-check')->assertDontSee('do not echo credentials');

        $connection = ProviderConnection::query()->firstOrFail();
        $this->assertSame(ProviderConnectionStatus::Error, $connection->connection_status);
        $this->assertStringNotContainsString('fictional-secret-error-check', $connection->last_sync_error);
        Log::shouldHaveReceived('warning')->with('provider_connection_event', \Mockery::on(function (array $context): bool {
            return ! in_array('fictional-secret-error-check', $context, true);
        }))->once();
    }

    public function test_moniepoint_webhooks_remain_disabled_until_current_provider_authentication_is_verified(): void
    {
        $this->connectedAgent();

        $this->postJson('/webhooks/providers/moniepoint', ['data' => ['businessId' => '43210']])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Moniepoint webhook verification is not enabled.');

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, ProviderWebhookReceipt::query()->count());
    }

    public function test_unsigned_or_invalid_signature_moniepoint_webhook_is_rejected_and_logged_without_payload(): void
    {
        $this->connectedAgent();
        Log::spy();

        $response = $this->postJson('/webhooks/providers/moniepoint', [
            'data' => ['businessId' => 43210, 'customerName' => 'Do not log this'],
        ])->assertStatus(503)->assertJsonPath('message', 'Moniepoint webhook verification is not enabled.');
        $response->assertDontSee('Do not log this');

        $this->assertSame(0, Transaction::query()->count());
        Log::shouldNotHaveReceived('warning');
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
        $this->postJson('/webhooks/providers/moniepoint', ['data' => ['businessId' => 43210]])->assertStatus(503);
    }

    public function test_provider_capabilities_distinguish_documented_from_unverified_and_implemented_features(): void
    {
        config([
            'provider_secrets.opay_direct_enabled' => false,
            'provider_secrets.palmpay_direct_enabled' => false,
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['name' => 'Moniepoint', 'slug' => 'moniepoint']);

        $providers = $this->actingAs($user)->getJson('/api/providers')->assertOk()->json('data');
        $moniepoint = collect($providers)->firstWhere('slug', 'moniepoint');
        $this->assertSame('unverified', $moniepoint['capabilities']['webhook_transactions']);
        $this->assertSame('planned', $moniepoint['capabilities']['api_transaction_sync']);
        Provider::factory()->create(['name' => 'OPay', 'slug' => 'opay']);
        Provider::factory()->create(['name' => 'PalmPay', 'slug' => 'palmpay']);
        $providers = $this->getJson('/api/providers')->assertOk()->json('data');
        $this->assertSame('documented', collect($providers)->firstWhere('slug', 'opay')['capabilities']['historical_sync']);
        $this->assertSame('coming_later', collect($providers)->firstWhere('slug', 'palmpay')['capabilities']['direct_connection']);
        $this->assertFalse(config('provider_secrets.opay_direct_enabled'));
        $this->assertFalse(config('provider_secrets.palmpay_direct_enabled'));
    }

    public function test_moniepoint_connection_api_is_hidden_when_feature_flag_is_off(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'moniepoint']);
        config(['provider_secrets.moniepoint_direct_enabled' => false]);

        $this->actingAs($user)->getJson('/api/providers/moniepoint/connection')->assertNotFound();
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

    public function test_moniepoint_webhook_ingestion_does_not_accept_unverified_event_schemas(): void
    {
        $this->connectedAgent();

        $this->postJson('/webhooks/providers/moniepoint', ['eventType' => 'V1_POS_TRANSACTION'])
            ->assertStatus(503);
        $this->assertSame(0, Transaction::query()->count());
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
        ]);
        $connection->refresh();

        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $agent, $provider, $connection];
    }
}
