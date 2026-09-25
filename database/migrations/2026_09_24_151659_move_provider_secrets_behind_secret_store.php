<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('provider_secret_records', function (Blueprint $table): void {
            $table->uuid('secret_reference')->primary();
            $table->foreignId('provider_connection_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->text('secrets');
            $table->timestamps();
        });

        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->uuid('secret_reference')->nullable()->unique()->after('provider_merchant_identifier');
        });

        foreach (DB::table('provider_connections')->get(['id', 'agent_profile_id', 'provider_id', 'credentials', 'webhook_secret']) as $connection) {
            $secrets = [];

            if ($connection->credentials !== null) {
                $decryptedCredentials = Crypt::decryptString($connection->credentials);
                $decodedCredentials = json_decode($decryptedCredentials, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($decodedCredentials)) {
                    throw new RuntimeException('Legacy provider credential data could not be migrated.');
                }

                foreach ($decodedCredentials as $key => $value) {
                    if (is_string($key) && is_string($value) && $value !== '' && ! $this->isProhibitedSecretName($key)) {
                        $secrets[$key] = $value;
                    }
                }
            }

            if ($connection->webhook_secret !== null) {
                $secrets['webhook_secret'] = Crypt::decryptString($connection->webhook_secret);
            }

            if ($secrets === []) {
                continue;
            }

            $reference = (string) Str::uuid();
            DB::table('provider_secret_records')->insert([
                'secret_reference' => $reference,
                'provider_connection_id' => $connection->id,
                'agent_profile_id' => $connection->agent_profile_id,
                'provider_id' => $connection->provider_id,
                'secrets' => Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('provider_connections')->where('id', $connection->id)->update(['secret_reference' => $reference]);
        }

        DB::table('provider_connections')->whereNotNull('last_sync_error')->update([
            'last_sync_error' => 'Provider connection requires verification. Please test the connection again.',
        ]);

        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn(['credentials', 'webhook_secret']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->text('credentials')->nullable();
            $table->text('webhook_secret')->nullable();
        });

        foreach (DB::table('provider_secret_records')->get() as $record) {
            $secrets = json_decode(Crypt::decryptString($record->secrets), true, flags: JSON_THROW_ON_ERROR);
            $webhookSecret = $secrets['webhook_secret'] ?? null;
            unset($secrets['webhook_secret']);

            DB::table('provider_connections')->where('id', $record->provider_connection_id)->update([
                'credentials' => $secrets === [] ? null : Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR)),
                'webhook_secret' => is_string($webhookSecret) ? Crypt::encryptString($webhookSecret) : null,
            ]);
        }

        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropUnique(['secret_reference']);
            $table->dropColumn('secret_reference');
        });

        Schema::dropIfExists('provider_secret_records');
    }

    private function isProhibitedSecretName(string $key): bool
    {
        $camelSeparated = preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $key) ?? $key;
        $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $camelSeparated) ?? '', '_'));

        return preg_match('/(^|_)(password|passwd|pin|otp|one_time_password|login_credential)(_|$)/', $normalized) === 1;
    }
};
