<?php

namespace App\Services;

use App\Contracts\ProviderSecretStore;
use App\Exceptions\ProviderSecretAccessDenied;
use App\Exceptions\ProviderSecretStoreUnavailable;
use App\Models\ProviderConnection;
use App\Models\ProviderSecretRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EncryptedDatabaseProviderSecretStore implements ProviderSecretStore
{
    public function store(ProviderConnection $connection, array $secrets): string
    {
        $this->assertLocalStoreEnabled();
        $persistedConnection = $this->persistedConnection($connection);

        if ($persistedConnection->secret_reference !== null) {
            throw new ProviderSecretAccessDenied;
        }

        return DB::transaction(function () use ($persistedConnection, $secrets): string {
            $reference = (string) Str::uuid();
            ProviderSecretRecord::query()->create([
                'secret_reference' => $reference,
                'provider_connection_id' => $persistedConnection->id,
                'agent_profile_id' => $persistedConnection->agent_profile_id,
                'provider_id' => $persistedConnection->provider_id,
                'secrets' => $this->validateSecrets($secrets),
            ]);
            $persistedConnection->secret_reference = $reference;
            $persistedConnection->save();

            return $reference;
        });
    }

    public function rotate(ProviderConnection $connection, array $secrets): string
    {
        $this->assertLocalStoreEnabled();
        $persistedConnection = $this->persistedConnection($connection);
        $validatedSecrets = $this->validateSecrets($secrets);

        return DB::transaction(function () use ($persistedConnection, $validatedSecrets): string {
            if ($persistedConnection->secret_reference !== null) {
                ProviderSecretRecord::query()
                    ->where('secret_reference', $persistedConnection->secret_reference)
                    ->where('provider_connection_id', $persistedConnection->id)
                    ->delete();
            }

            $reference = (string) Str::uuid();
            ProviderSecretRecord::query()->create([
                'secret_reference' => $reference,
                'provider_connection_id' => $persistedConnection->id,
                'agent_profile_id' => $persistedConnection->agent_profile_id,
                'provider_id' => $persistedConnection->provider_id,
                'secrets' => $validatedSecrets,
            ]);
            $persistedConnection->secret_reference = $reference;
            $persistedConnection->save();

            return $reference;
        });
    }

    public function retrieve(ProviderConnection $connection): array
    {
        $this->assertLocalStoreEnabled();
        $persistedConnection = $this->persistedConnection($connection);

        if (! is_string($persistedConnection->secret_reference) || $persistedConnection->secret_reference === '') {
            throw new ProviderSecretAccessDenied;
        }

        $record = ProviderSecretRecord::query()
            ->where('secret_reference', $persistedConnection->secret_reference)
            ->where('provider_connection_id', $persistedConnection->id)
            ->where('agent_profile_id', $persistedConnection->agent_profile_id)
            ->where('provider_id', $persistedConnection->provider_id)
            ->first();

        if (! $record || ! is_array($record->secrets)) {
            throw new ProviderSecretAccessDenied;
        }

        return $record->secrets;
    }

    public function revoke(ProviderConnection $connection): void
    {
        $this->assertLocalStoreEnabled();
        $persistedConnection = $this->persistedConnection($connection);

        DB::transaction(function () use ($persistedConnection): void {
            if ($persistedConnection->secret_reference !== null) {
                ProviderSecretRecord::query()
                    ->where('secret_reference', $persistedConnection->secret_reference)
                    ->where('provider_connection_id', $persistedConnection->id)
                    ->where('agent_profile_id', $persistedConnection->agent_profile_id)
                    ->where('provider_id', $persistedConnection->provider_id)
                    ->delete();
            }

            $persistedConnection->secret_reference = null;
            $persistedConnection->save();
        });
    }

    private function assertLocalStoreEnabled(): void
    {
        if (app()->environment('production') || config('provider_secrets.driver') !== 'database') {
            throw new ProviderSecretStoreUnavailable;
        }
    }

    private function persistedConnection(ProviderConnection $connection): ProviderConnection
    {
        $persisted = ProviderConnection::query()->find($connection->getKey());

        if (! $persisted
            || $persisted->agent_profile_id !== $connection->agent_profile_id
            || $persisted->provider_id !== $connection->provider_id) {
            throw new ProviderSecretAccessDenied;
        }

        return $persisted;
    }

    /** @param array<string, mixed> $secrets
     * @return array<string, string>
     */
    private function validateSecrets(array $secrets): array
    {
        if ($secrets === []) {
            throw new ProviderSecretAccessDenied;
        }

        $validated = [];

        foreach ($secrets as $key => $value) {
            if (! is_string($key) || ! is_string($value) || $value === '' || $this->isProhibitedSecretName($key)) {
                throw new ProviderSecretAccessDenied;
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    private function isProhibitedSecretName(string $key): bool
    {
        $camelSeparated = preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $key) ?? $key;
        $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $camelSeparated) ?? '', '_'));

        return preg_match('/(^|_)(password|passwd|pin|otp|one_time_password|login_credential)(_|$)/', $normalized) === 1;
    }
}
