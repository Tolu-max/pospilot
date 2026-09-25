<?php

namespace App\Contracts;

use App\Models\ProviderConnection;
use Carbon\CarbonImmutable;

final class DemoConnector implements PaymentProviderConnector
{
    public function transactions(ProviderConnection $connection, ?CarbonImmutable $since = null): iterable
    {
        return [];
    }

    public function settlements(ProviderConnection $connection, ?CarbonImmutable $since = null): iterable
    {
        return [];
    }
}
