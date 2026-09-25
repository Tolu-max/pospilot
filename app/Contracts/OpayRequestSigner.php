<?php

namespace App\Contracts;

use App\Data\OpayBusinessCredentials;

interface OpayRequestSigner
{
    /** @param array<string, mixed> $payload */
    public function sign(array $payload, OpayBusinessCredentials $credentials): string;
}
