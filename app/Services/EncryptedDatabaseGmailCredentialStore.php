<?php

namespace App\Services;

use App\Contracts\GmailCredentialStore;
use App\Exceptions\GmailCredentialStoreUnavailable;
use App\Models\GmailConnection;
use App\Models\GmailCredential;

final class EncryptedDatabaseGmailCredentialStore implements GmailCredentialStore
{
    public function store(GmailConnection $connection, array $tokens): void
    {
        $this->assertEnabled();
        $persisted = GmailConnection::query()->whereKey($connection->id)
            ->where('agent_profile_id', $connection->agent_profile_id)->firstOrFail();
        $current = $persisted->credential;
        $refreshToken = $tokens['refresh_token'] ?? $current?->refresh_token;

        $persisted->credential()->updateOrCreate([], [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $refreshToken,
            'token_expires_at' => now()->addSeconds(max(1, $tokens['expires_in'])),
        ]);
    }

    public function retrieve(GmailConnection $connection): array
    {
        $this->assertEnabled();

        $credential = GmailCredential::query()->where('gmail_connection_id', $connection->id)
            ->whereHas('connection', fn ($query) => $query->where('agent_profile_id', $connection->agent_profile_id))
            ->first();

        if (! $credential || ! is_string($credential->access_token) || $credential->access_token === '') {
            throw new GmailCredentialStoreUnavailable;
        }

        return [
            'access_token' => $credential->access_token,
            'refresh_token' => $credential->refresh_token,
            'token_expires_at' => $credential->token_expires_at,
        ];
    }

    public function forget(GmailConnection $connection): void
    {
        $this->assertEnabled();
        GmailCredential::query()->where('gmail_connection_id', $connection->id)
            ->whereHas('connection', fn ($query) => $query->where('agent_profile_id', $connection->agent_profile_id))
            ->delete();
    }

    private function assertEnabled(): void
    {
        if (config('gmail_statement.token_store') !== 'database' || app()->environment('production')) {
            throw new GmailCredentialStoreUnavailable;
        }
    }
}
