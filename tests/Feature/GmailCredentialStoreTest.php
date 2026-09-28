<?php

namespace Tests\Feature;

use App\Exceptions\GmailCredentialStoreUnavailable;
use App\Models\AgentProfile;
use App\Models\GmailConnection;
use App\Services\EncryptedFilesystemGmailCredentialStore;
use App\Services\GmailIntegrationConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GmailCredentialStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('gmail_credentials');
    }

    public function test_credentials_are_encrypted_and_refresh_token_survives_rotation_without_a_new_one(): void
    {
        $connection = $this->connectionFor(AgentProfile::factory()->create());
        $store = app(EncryptedFilesystemGmailCredentialStore::class);

        $store->store($connection, [
            'access_token' => 'fictional-access-secret',
            'refresh_token' => 'fictional-refresh-secret',
            'expires_in' => 3600,
        ]);
        $store->store($connection, [
            'access_token' => 'rotated-fictional-access-secret',
            'refresh_token' => null,
            'expires_in' => 1800,
        ]);

        $credentialFile = collect(Storage::disk('gmail_credentials')->allFiles())->first(fn (string $file): bool => str_ends_with($file, '.enc'));

        $this->assertNotNull($credentialFile);
        $encryptedContents = Storage::disk('gmail_credentials')->get($credentialFile);
        $this->assertStringNotContainsString('fictional-access-secret', $encryptedContents);
        $this->assertStringNotContainsString('fictional-refresh-secret', $encryptedContents);
        $this->assertSame('rotated-fictional-access-secret', $store->retrieve($connection)['access_token']);
        $this->assertSame('fictional-refresh-secret', $store->retrieve($connection)['refresh_token']);
    }

    public function test_credentials_cannot_be_retrieved_using_another_agents_connection_scope(): void
    {
        $owner = AgentProfile::factory()->create();
        $otherAgent = AgentProfile::factory()->create();
        $connection = $this->connectionFor($owner);
        app(EncryptedFilesystemGmailCredentialStore::class)->store($connection, [
            'access_token' => 'fictional-access-secret',
            'refresh_token' => 'fictional-refresh-secret',
            'expires_in' => 3600,
        ]);
        $connection->agent_profile_id = $otherAgent->id;

        $this->expectException(GmailCredentialStoreUnavailable::class);
        app(EncryptedFilesystemGmailCredentialStore::class)->retrieve($connection);
    }

    public function test_forget_removes_the_encrypted_credentials(): void
    {
        $connection = $this->connectionFor(AgentProfile::factory()->create());
        $store = app(EncryptedFilesystemGmailCredentialStore::class);
        $store->store($connection, [
            'access_token' => 'fictional-access-secret',
            'refresh_token' => 'fictional-refresh-secret',
            'expires_in' => 3600,
        ]);

        $store->forget($connection);

        $credentialFiles = array_filter(
            Storage::disk('gmail_credentials')->allFiles(),
            fn (string $file): bool => str_ends_with($file, '.enc'),
        );

        $this->assertSame([], array_values($credentialFiles));
        $this->expectException(GmailCredentialStoreUnavailable::class);
        $store->retrieve($connection);
    }

    public function test_production_configuration_requires_a_registered_external_token_store(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config([
            'app.env' => 'production',
            'gmail_statement.client_id' => 'fictional-client-id',
            'gmail_statement.client_secret' => 'fictional-client-secret',
            'gmail_statement.redirect_uri' => 'https://pospilot.example/integrations/gmail/callback',
            'gmail_statement.token_store' => 'database',
        ]);

        $this->assertFalse(app(GmailIntegrationConfiguration::class)->isConfigured());

        config([
            'gmail_statement.token_store' => 'external',
            'gmail_statement.external_store_class' => EncryptedFilesystemGmailCredentialStore::class,
        ]);

        $this->assertTrue(app(GmailIntegrationConfiguration::class)->isConfigured());
    }

    private function connectionFor(AgentProfile $agent): GmailConnection
    {
        return GmailConnection::query()->create([
            'agent_profile_id' => $agent->id,
            'status' => 'connected',
            'provider_rules' => ['opay'],
        ]);
    }
}
