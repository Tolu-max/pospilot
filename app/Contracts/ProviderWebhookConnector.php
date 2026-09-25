<?php

namespace App\Contracts;

use App\Data\NormalizedTransactionData;
use App\Models\Provider;
use Illuminate\Http\Request;

interface ProviderWebhookConnector
{
    public function verify(Request $request, Provider $provider): bool;

    /** @return iterable<NormalizedTransactionData> */
    public function transactions(Request $request, Provider $provider): iterable;
}
