<?php

namespace App\Services;

use App\Contracts\PaymentProviderConnector;
use App\Models\ProviderConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class SyncProviderTransactions
{
    public function __construct(private readonly TransactionIngestionService $ingestion) {}

    /** @return array{received:int, imported:int, duplicates:int, failed:int} */
    public function handle(ProviderConnection $connection, PaymentProviderConnector $connector): array
    {
        $received = $imported = $duplicates = $failed = 0;
        $since = $connection->last_synced_at ? CarbonImmutable::parse($connection->last_synced_at) : null;
        try {
            foreach ($connector->transactions($connection, $since) as $data) {
                $received++;
                try {
                    if ($data->provider->id !== $connection->provider_id) {
                        throw new \InvalidArgumentException('Connector returned a transaction for the wrong provider.');
                    } $result = $this->ingestion->ingest($connection->agentProfile, $data);
                    $result['status'] === 'duplicate' ? $duplicates++ : $imported++;
                } catch (\Throwable) {
                    $failed++;
                }
            }
            $connection->update(['last_synced_at' => now(), 'last_sync_status' => $failed > 0 ? 'completed_with_errors' : 'completed', 'last_sync_error' => $failed > 0 ? "{$failed} transaction(s) failed normalization or persistence." : null]);
        } catch (\Throwable $exception) {
            $connection->update([
                'last_sync_status' => 'failed',
                'last_sync_error' => 'Provider transaction synchronization failed.',
            ]);
            Log::warning('provider_sync_failed', [
                'provider_connection_id' => $connection->id,
                'provider_id' => $connection->provider_id,
                'exception_type' => $exception::class,
            ]);

            throw new RuntimeException('Provider transaction synchronization failed.');
        }

        return compact('received', 'imported', 'duplicates', 'failed');
    }
}
