<?php

namespace App\Services;

use App\Contracts\ProviderSecretStore;
use App\Enums\ProviderConnectionStatus;
use App\Exceptions\ProviderSecretAccessDenied;
use App\Exceptions\ProviderSecretStoreUnavailable;
use App\Models\ProviderConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MoniepointConnectionService
{
    public function __construct(private readonly ProviderSecretStore $secrets) {}

    /** @return array{verified:bool,status:string,error:?string} */
    public function test(ProviderConnection $connection): array
    {
        $businessId = $connection->provider_merchant_identifier;

        if (! is_string($businessId) || $businessId === '') {
            return $this->recordFailure($connection, 'credentials_incomplete');
        }

        try {
            $apiKey = $this->secrets->retrieve($connection)['api_key'] ?? null;

            if (! is_string($apiKey) || $apiKey === '') {
                return $this->recordFailure($connection, 'credentials_incomplete');
            }

            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->connectTimeout(3)
                ->timeout(5)
                ->get(config('moniepoint.introspection_url'));
            $businesses = $response->json('businesses');
            $verified = $response->successful()
                && is_array($businesses)
                && collect($businesses)->contains(fn (mixed $business): bool => is_array($business)
                    && isset($business['id'])
                    && (string) $business['id'] === $businessId);

            if (! $verified) {
                return $this->recordFailure($connection, 'credential_or_business_verification_failed');
            }

            $connection->update([
                'connection_status' => ProviderConnectionStatus::Active,
                'last_sync_status' => 'verified',
                'last_sync_error' => null,
            ]);
            Log::notice('provider_connection_event', [
                'event' => 'connection_verified',
                'provider' => 'moniepoint',
                'provider_connection_id' => $connection->id,
                'agent_profile_id' => $connection->agent_profile_id,
            ]);

            return ['verified' => true, 'status' => 'verified', 'error' => null];
        } catch (ProviderSecretStoreUnavailable) {
            return ['verified' => false, 'status' => 'secure_store_unavailable', 'error' => 'Provider secret storage is not configured.'];
        } catch (ProviderSecretAccessDenied) {
            return $this->recordFailure($connection, 'credentials_incomplete');
        } catch (\Throwable) {
            return $this->recordFailure($connection, 'connection_test_unavailable');
        }
    }

    /** @return array{verified:false,status:string,error:string} */
    private function recordFailure(ProviderConnection $connection, string $reason): array
    {
        $connection->update([
            'connection_status' => ProviderConnectionStatus::Error,
            'last_sync_status' => 'failed',
            'last_sync_error' => 'Moniepoint connection verification failed. Check credentials and try again.',
        ]);
        Log::warning('provider_connection_event', [
            'event' => 'connection_failed',
            'provider' => 'moniepoint',
            'provider_connection_id' => $connection->id,
            'agent_profile_id' => $connection->agent_profile_id,
            'reason' => $reason,
        ]);

        return ['verified' => false, 'status' => 'failed', 'error' => 'Moniepoint connection verification failed. Check credentials and try again.'];
    }
}
