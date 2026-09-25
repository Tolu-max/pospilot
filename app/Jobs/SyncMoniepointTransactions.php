<?php

namespace App\Jobs;

use App\Contracts\MoniepointTransactionHistoryConnector;
use App\Enums\ProviderConnectionStatus;
use App\Models\Provider;
use App\Models\ProviderConnection;
use App\Services\TransactionIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class SyncMoniepointTransactions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $providerConnectionId) {}

    public function handle(TransactionIngestionService $ingestion): void
    {
        $connection = ProviderConnection::query()
            ->whereKey($this->providerConnectionId)
            ->where('connection_type', 'api')
            ->where('connection_status', ProviderConnectionStatus::Active)
            ->whereHas('provider', fn ($query) => $query->where('slug', 'moniepoint'))
            ->first();

        if (! $connection) {
            return;
        }

        if (! app()->bound(MoniepointTransactionHistoryConnector::class)) {
            $connection->update([
                'last_sync_status' => 'blocked_documentation',
                'last_sync_error' => 'Moniepoint transaction history API is not configured.',
            ]);

            return;
        }

        $provider = Provider::query()->findOrFail($connection->provider_id);
        $since = $connection->last_synced_at ? CarbonImmutable::parse($connection->last_synced_at) : null;
        $imported = 0;
        $duplicates = 0;

        try {
            $connector = app(MoniepointTransactionHistoryConnector::class);

            foreach ($connector->transactionsSince($connection, $since) as $normalized) {
                $connection->refresh();

                if ($connection->connection_status !== ProviderConnectionStatus::Active) {
                    return;
                }

                if ($normalized->provider->id !== $provider->id) {
                    throw new \InvalidArgumentException('Moniepoint history connector provider mismatch.');
                }

                $result = $ingestion->ingest($connection->agentProfile, $normalized);
                $result['status'] === 'duplicate' ? $duplicates++ : $imported++;
            }

            $connection->update([
                'last_synced_at' => now(),
                'last_sync_status' => 'completed',
                'last_sync_error' => null,
            ]);
        } catch (\Throwable) {
            $connection->update([
                'last_sync_status' => 'failed',
                'last_sync_error' => 'Moniepoint transaction history synchronization failed.',
            ]);
            Log::warning('provider_sync_failed', [
                'provider' => 'moniepoint',
                'provider_connection_id' => $connection->id,
                'agent_profile_id' => $connection->agent_profile_id,
                'imported' => $imported,
                'duplicates' => $duplicates,
            ]);
        }
    }
}
