<?php

namespace App\Contracts;

use App\Data\OpayBusinessCredentials;

interface OpayResponseVerifier
{
    /** @param array<string, mixed> $payload */
    public function verify(array $payload, string $signature, OpayBusinessCredentials $credentials): bool;
}
