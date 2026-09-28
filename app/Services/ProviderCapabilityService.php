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

        foreach ($configured as $capability => $status) {
            $capabilities[$capability] = is_string($status)
                ? (ProviderCapabilityStatus::tryFrom($status)?->value ?? ProviderCapabilityStatus::Unknown->value)
                : ProviderCapabilityStatus::Unknown->value;
        }

        return $capabilities;
    }
}
