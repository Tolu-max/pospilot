<?php

namespace Tests\Feature;

use App\Contracts\ProviderSecretStore;
use App\Exceptions\ProviderSecretAccessDenied;
use App\Exceptions\ProviderSecretStoreUnavailable;
use App\Models\AgentProfile;
use App\Models\DailyClosing;
use App\Models\Provider;
use App\Models\ProviderConnection;
use App\Models\ProviderSecretRecord;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\UnavailableProviderSecretStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_provider_webhook_is_rejected_without_provider_details(): void
    {
        $provider = Provider::factory()->create(['slug' => 'opay']);
        $response = $this->postJson('/webhooks/providers/opay', ['amount' => '100']);
        $response->assertStatus(501)->assertJsonPath('message', 'Provider webhook ingestion is disabled until signature verification is configured.');
        $this->assertStringNotContainsString('opay', $response->json('message'));
    }

    public function test_provider_credentials_are_encrypted_and_excluded_from_serialization(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        $connection = ProviderConnection::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'connection_type' => 'api', 'connection_status' => 'active']);
        $store = app(ProviderSecretStore::class);
        $store->store($connection, ['api_key' => 'secret-value', 'webhook_secret' => 'webhook-value']);
        $connection->refresh();

        $this->assertSame(['api_key' => 'secret-value', 'webhook_secret' => 'webhook-value'], $store->retrieve($connection));
        $this->assertArrayNotHasKey('secret_reference', $connection->toArray());
        $this->assertArrayNotHasKey('api_key', $connection->toArray());
        $this->assertArrayNotHasKey('secrets', ProviderSecretRecord::query()->firstOrFail()->toArray());
        $this->assertDatabaseMissing('provider_connections', ['id' => $connection->id, 'secret_reference' => 'secret-value']);
        $this->assertDatabaseMissing('provider_secret_records', ['secret_reference' => $connection->secret_reference, 'secrets' => 'secret-value']);
        $ciphertext = DB::table('provider_secret_records')->where('secret_reference', $connection->secret_reference)->value('secrets');
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString('secret-value', $ciphertext);
        $this->assertStringNotContainsString('webhook-value', $ciphertext);
    }

    public function test_provider_secrets_are_scoped_to_the_connection_owner_and_provider(): void
    {
        $agent = AgentProfile::factory()->create();
        $otherAgent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        $connection = ProviderConnection::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'connection_type' => 'api', 'connection_status' => 'active']);
        $store = app(ProviderSecretStore::class);
        $store->store($connection, ['api_key' => 'owner-only-secret']);

        $misattributedConnection = $connection->fresh();
        $misattributedConnection->agent_profile_id = $otherAgent->id;

        try {
            $store->retrieve($misattributedConnection);
            $this->fail('A mismatched agent scope must not retrieve another agent’s provider secret.');
        } catch (ProviderSecretAccessDenied) {
            $this->assertTrue(true);
        }
    }

    public function test_secret_rotation_replaces_the_old_reference_and_revocation_removes_access(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        $connection = ProviderConnection::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'connection_type' => 'api', 'connection_status' => 'active']);
        $store = app(ProviderSecretStore::class);
        $oldReference = $store->store($connection, ['api_key' => 'old-secret']);
        $connection->refresh();
        $newReference = $store->rotate($connection, ['api_key' => 'new-secret']);
        $connection->refresh();

        $this->assertNotSame($oldReference, $newReference);
        $this->assertSame(['api_key' => 'new-secret'], $store->retrieve($connection));
        $this->assertDatabaseMissing('provider_secret_records', ['secret_reference' => $oldReference]);

        $store->revoke($connection);
        $this->assertNull($connection->fresh()->secret_reference);
        $this->assertDatabaseMissing('provider_secret_records', ['secret_reference' => $newReference]);

        try {
            $store->retrieve($connection->fresh());
            $this->fail('Revoked secrets must not remain retrievable.');
        } catch (ProviderSecretAccessDenied) {
            $this->assertTrue(true);
        }
    }

    public function test_database_secret_store_refuses_production_or_external_store_configuration(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        $connection = ProviderConnection::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'connection_type' => 'api', 'connection_status' => 'active']);
        config(['provider_secrets.driver' => 'external']);

        try {
            app(ProviderSecretStore::class)->store($connection, ['api_key' => 'secret-value']);
            $this->fail('The encrypted local store must not act as the configured external production store.');
        } catch (ProviderSecretStoreUnavailable) {
            $this->assertNull($connection->fresh()->secret_reference);
        }
    }

    public function test_local_secret_store_refuses_production_environment_even_if_database_driver_is_selected(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        $connection = ProviderConnection::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'connection_type' => 'api', 'connection_status' => 'active']);
        config(['provider_secrets.driver' => 'database']);
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            app(ProviderSecretStore::class)->store($connection, ['api_key' => 'secret-value']);
            $this->fail('The encrypted database store must be unavailable in production.');
        } catch (ProviderSecretStoreUnavailable) {
            $this->assertNull($connection->fresh()->secret_reference);
        }
    }

    public function test_missing_external_secret_store_fails_closed_with_safe_api_response(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'moniepoint']);
        config(['provider_secrets.moniepoint_direct_enabled' => true]);
        $this->app->instance(ProviderSecretStore::class, new UnavailableProviderSecretStore);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->putJson('/api/providers/moniepoint/connection', [
            'api_key' => 'fictional-live-key',
            'business_id' => '43210',
        ])->assertStatus(503)
            ->assertJsonPath('message', 'Provider secret storage is not configured.')
            ->assertDontSee('fictional-live-key');

        $this->assertDatabaseCount('provider_connections', 0);
    }

    public function test_production_without_external_provider_secret_store_refuses_secret_storage(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create(['slug' => 'moniepoint']);
        $connection = ProviderConnection::create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'connection_type' => 'api',
            'connection_status' => 'inactive',
        ]);
        config(['provider_secrets.driver' => 'external', 'provider_secrets.external_store_class' => null]);
        $this->app->detectEnvironment(fn (): string => 'production');
        (new AppServiceProvider($this->app))->register();

        try {
            app(ProviderSecretStore::class)->store($connection, ['api_key' => 'fictional-production-key']);
            $this->fail('Production without a configured external store must reject provider credentials.');
        } catch (ProviderSecretStoreUnavailable) {
            $this->assertNull($connection->fresh()->secret_reference);
        }

        $this->assertDatabaseCount('provider_secret_records', 0);
    }

    public function test_provider_secret_store_rejects_account_password_pin_and_otp_fields(): void
    {
        $agent = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        $connection = ProviderConnection::create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'connection_type' => 'api', 'connection_status' => 'active']);

        try {
            app(ProviderSecretStore::class)->store($connection, ['api_key' => 'official-key', 'login_password' => 'fictional-password']);
            $this->fail('Interactive account credentials must not be persisted.');
        } catch (ProviderSecretAccessDenied) {
            $this->assertNull($connection->fresh()->secret_reference);
        }
    }

    public function test_daily_closing_is_tenant_scoped_and_cannot_be_finalized_twice(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $user->id]);
        $other = User::factory()->create();
        $otherAgent = AgentProfile::factory()->create(['user_id' => $other->id]);
        $closing = DailyClosing::factory()->create(['agent_profile_id' => $otherAgent->id, 'closing_date' => '2026-09-24', 'status' => 'finalized']);
        $this->actingAs($user)->getJson('/api/daily-closings/'.$closing->id)->assertNotFound();
        $this->actingAs($other)->postJson('/api/daily-closings/'.$closing->id.'/finalize')->assertStatus(422);
    }

    public function test_mass_assignment_payload_cannot_change_terminal_ownership(): void
    {
        $user = User::factory()->create();
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $other = AgentProfile::factory()->create();
        $provider = Provider::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/terminals', ['agent_profile_id' => $other->id, 'provider_id' => $provider->id, 'name' => 'Scoped terminal']);
        $response->assertCreated();
        $this->assertDatabaseHas('terminals', ['agent_profile_id' => $agent->id, 'name' => 'Scoped terminal']);
        $this->assertDatabaseMissing('terminals', ['agent_profile_id' => $other->id, 'name' => 'Scoped terminal']);
    }

    public function test_import_preview_rejects_more_than_five_thousand_rows(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create(['slug' => 'opay']);
        $rows = "reference,amount,provider_fee,status,transaction_date\n".str_repeat("REF,100,1,successful,2026-09-24\n", 5001);
        $file = UploadedFile::fake()->createWithContent('large.csv', $rows);
        $this->actingAs($user)->post('/transactions/import/preview', ['provider_id' => $provider->id, 'file' => $file], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'The CSV cannot contain more than 5,000 data rows.');
    }
}
