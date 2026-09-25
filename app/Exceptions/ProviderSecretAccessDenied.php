<?php

namespace App\Exceptions;

use RuntimeException;

final class ProviderSecretAccessDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Provider secret access is unavailable for this connection.');
    }
}
