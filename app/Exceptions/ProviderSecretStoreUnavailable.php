<?php

namespace App\Exceptions;

use RuntimeException;

final class ProviderSecretStoreUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Provider secret storage is not configured for this environment.');
    }
}
