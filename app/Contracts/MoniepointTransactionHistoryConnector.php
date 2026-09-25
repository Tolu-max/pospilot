<?php

namespace App\Contracts;

use App\Data\NormalizedTransactionData;
use App\Models\ProviderConnection;
use Carbon\CarbonImmutable;

interface MoniepointTransactionHistoryConnector
{
    /** @return iterable<NormalizedTransactionData> */
    public function transactionsSince(ProviderConnection $connection, ?CarbonImmutable $since): iterable;
}
