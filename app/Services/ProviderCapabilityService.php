<?php

namespace App\Services;

use App\Enums\ProviderCapabilityStatus;
use App\Models\Provider;

final class ProviderCapabilityService
{
    /** @return array<string, string> */
    public function for(Provider $provider): array
    {
        $configured = config('pospilot.provider_capabilities.'.$provider->slug, []);
        $capabilities = [];
        foreach (['csv_transaction_import', 'csv_settlement_import', 'api_transaction_sync', 'webhook_transactions', 'settlement_sync', 'balance_sync'] as $capability) {
            $capabilities[$capability] = ProviderCapabilityStatus::tryFrom($configured[$capability] ?? ProviderCapabilityStatus::Unknown->value)?->value ?? ProviderCapabilityStatus::Unknown->value;
        }

        return $capabilities;
    }
}
