<?php

namespace App\Contracts;

use App\Models\GmailConnection;
use Illuminate\Support\Carbon;

interface GmailCredentialStore
{
    /** @param array{access_token:string,refresh_token:?string,expires_in:int} $tokens */
    public function store(GmailConnection $connection, array $tokens): void;

    /** @return array{access_token:string,refresh_token:?string,token_expires_at:?Carbon} */
    public function retrieve(GmailConnection $connection): array;

    public function forget(GmailConnection $connection): void;
}
