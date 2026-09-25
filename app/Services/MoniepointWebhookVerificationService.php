<?php

namespace App\Services;

use App\Contracts\ProviderSecretStore;
use App\Enums\ProviderConnectionStatus;
use App\Exceptions\ProviderSecretAccessDenied;
use App\Exceptions\ProviderSecretStoreUnavailable;
use App\Models\Provider;
use App\Models\ProviderConnection;

final class MoniepointWebhookVerificationService
{
    public function __construct(private readonly ProviderSecretStore $secrets) {}

    public function connectionFor(string $eventId, string $timestamp, string $signature, string $rawBody, string $businessId): ?ProviderConnection
    {
        if ($eventId === '' || ! ctype_digit($timestamp) || $signature === '' || $businessId === '') {
            return null;
        }

        $provider = Provider::query()->where('slug', 'moniepoint')->first();

        if (! $provider) {
            return null;
        }

        $connections = ProviderConnection::query()
            ->where('provider_id', $provider->id)
            ->where('connection_type', 'api')
            ->where('connection_status', ProviderConnectionStatus::Active)
            ->where('provider_merchant_identifier', $businessId)
            ->whereNotNull('secret_reference')
            ->get();

        foreach ($connections as $connection) {
            try {
                $secret = $this->secrets->retrieve($connection)['webhook_secret'] ?? null;
            } catch (ProviderSecretAccessDenied|ProviderSecretStoreUnavailable) {
                continue;
            }

            if (! is_string($secret) || $secret === '') {
                continue;
            }

            $signedPayload = implode('__', [$eventId, $timestamp, $rawBody]);
            $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $secret, true));

            if (hash_equals($expectedSignature, $signature)) {
                return $connection;
            }
        }

        return null;
    }
}
