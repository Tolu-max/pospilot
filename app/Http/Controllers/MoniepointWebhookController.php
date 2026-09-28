<?php

namespace App\Http\Controllers;

use App\Enums\ProviderConnectionStatus;
use App\Models\Provider;
use App\Models\ProviderConnection;
use App\Models\ProviderWebhookReceipt;
use App\Services\MoniepointTransactionNormalizer;
use App\Services\MoniepointWebhookVerificationService;
use App\Services\TransactionIngestionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class MoniepointWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        MoniepointWebhookVerificationService $verification,
        MoniepointTransactionNormalizer $normalizer,
        TransactionIngestionService $ingestion,
    ): JsonResponse {
        if (! config('provider_secrets.moniepoint_webhooks_verified')) {
            return response()->json(['message' => 'Moniepoint webhook verification is not enabled.'], 503);
        }

        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);
        $eventId = (string) $request->header('moniepoint-webhook-id', '');
        $timestamp = (string) $request->header('moniepoint-webhook-timestamp', '');
        $signature = (string) $request->header('moniepoint-webhook-signature', '');

        if (strlen($rawBody) > 262144 || ! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            return $this->reject('invalid_payload');
        }

        $businessId = $payload['data']['businessId'] ?? null;

        if (! is_scalar($businessId)) {
            return $this->reject('business_id_missing');
        }

        $connection = $verification->connectionFor($eventId, $timestamp, $signature, $rawBody, (string) $businessId);

        if (! $connection) {
            return $this->reject('signature_or_connection_invalid');
        }

        if (ProviderWebhookReceipt::query()->where('provider_connection_id', $connection->id)->where('event_id', $eventId)->exists()) {
            return response()->json(['accepted' => true, 'duplicate' => true]);
        }

        try {
            $provider = Provider::query()->findOrFail($connection->provider_id);
            $normalized = $normalizer->normalize($provider, $payload, $eventId);

            DB::transaction(function () use ($connection, $eventId, $normalized, $ingestion): void {
                $activeConnection = ProviderConnection::query()->whereKey($connection->id)->lockForUpdate()->first();

                if (! $activeConnection || $activeConnection->connection_status !== ProviderConnectionStatus::Active) {
                    throw new InvalidArgumentException('Moniepoint connection is no longer active.');
                }

                $receipt = ProviderWebhookReceipt::query()->create([
                    'provider_connection_id' => $activeConnection->id,
                    'event_id' => $eventId,
                    'status' => 'processed',
                ]);
                $result = $ingestion->ingest($activeConnection->agentProfile, $normalized);
                $receipt->update(['transaction_id' => $result['transaction']?->id]);
                $activeConnection->update(['last_webhook_at' => now(), 'last_sync_status' => 'webhook_received']);
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['accepted' => true, 'duplicate' => true]);
        } catch (InvalidArgumentException) {
            return $this->reject('unsupported_or_invalid_transaction');
        } catch (\Throwable $exception) {
            Log::error('moniepoint_webhook_processing_failed', [
                'provider_connection_id' => $connection->id,
                'agent_profile_id' => $connection->agent_profile_id,
                'exception_type' => $exception::class,
            ]);

            return response()->json(['message' => 'Webhook processing failed.'], 500);
        }

        return response()->json(['accepted' => true, 'duplicate' => false]);
    }

    private function reject(string $reason): JsonResponse
    {
        Log::warning('provider_webhook_rejected', ['provider' => 'moniepoint', 'reason' => $reason]);

        return response()->json(['message' => 'Webhook rejected.'], 401);
    }
}
