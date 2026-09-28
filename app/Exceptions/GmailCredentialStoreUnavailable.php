<?php

namespace App\Exceptions;

use RuntimeException;

final class GmailCredentialStoreUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Secure Gmail token storage is unavailable.');
    }
}
