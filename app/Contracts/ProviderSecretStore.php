<?php

namespace App\Contracts;

use App\Models\ProviderConnection;

interface ProviderSecretStore
{
    /** @param array<string, string> $secrets */
    public function store(ProviderConnection $connection, array $secrets): string;

    /** @param array<string, string> $secrets */
    public function rotate(ProviderConnection $connection, array $secrets): string;

    /** @return array<string, string> */
    public function retrieve(ProviderConnection $connection): array;

    public function revoke(ProviderConnection $connection): void;
}
