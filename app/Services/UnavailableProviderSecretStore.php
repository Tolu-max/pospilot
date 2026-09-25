<?php

namespace App\Services;

use App\Contracts\ProviderSecretStore;
use App\Exceptions\ProviderSecretStoreUnavailable;
use App\Models\ProviderConnection;

final class UnavailableProviderSecretStore implements ProviderSecretStore
{
    public function store(ProviderConnection $connection, array $secrets): string
    {
        throw new ProviderSecretStoreUnavailable;
    }

    public function rotate(ProviderConnection $connection, array $secrets): string
    {
        throw new ProviderSecretStoreUnavailable;
    }

    public function retrieve(ProviderConnection $connection): array
    {
        throw new ProviderSecretStoreUnavailable;
    }

    public function revoke(ProviderConnection $connection): void
    {
        throw new ProviderSecretStoreUnavailable;
    }
}
