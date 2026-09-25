<?php

namespace App\Contracts;

use App\Data\NormalizedTransactionData;
use App\Data\OpayBusinessCredentials;
use App\Models\ProviderConnection;
use Carbon\CarbonImmutable;

final class OpayBusinessConnector implements PaymentProviderConnector
{
    public function __construct(private readonly OpayBusinessCredentials $credentials, private readonly OpayRequestSigner $signer, private readonly OpayResponseVerifier $verifier) {}

    /** @return iterable<NormalizedTransactionData> */
    public function transactions(ProviderConnection $connection, ?CarbonImmutable $since = null): iterable
    {
        throw new \LogicException('OPay Business API connector is disabled until verified credentials and endpoint requirements are configured.');
    }

    /** @return iterable<array<string, mixed>> */
    public function settlements(ProviderConnection $connection, ?CarbonImmutable $since = null): iterable
    {
        throw new \LogicException('OPay Business API connector is disabled until verified credentials and endpoint requirements are configured.');
    }
}
