<?php

namespace App\Contracts;

use App\Data\NormalizedTransactionData;
use App\Models\ProviderConnection;
use Carbon\CarbonImmutable;

interface PaymentProviderConnector
{
    /** @return iterable<NormalizedTransactionData> */
    public function transactions(ProviderConnection $connection, ?CarbonImmutable $since = null): iterable;

    /** @return iterable<array<string, mixed>> */
    public function settlements(ProviderConnection $connection, ?CarbonImmutable $since = null): iterable;
}
