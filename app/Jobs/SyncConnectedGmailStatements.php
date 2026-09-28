<?php

namespace App\Jobs;

use App\Models\GmailConnection;
use App\Models\GmailStatementMessage;
use App\Services\Gmail\GmailStatementConnector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SyncConnectedGmailStatements implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?int $connectionId = null) {}

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return $this->connectionId === null
            ? 'connected-gmail-statements:all'
            : 'connected-gmail-statements:'.$this->connectionId;
    }

    public function handle(GmailStatementConnector $connector): void
    {
        $expiredMessages = GmailStatementMessage::where('status', 'needs_setup')
            ->where('created_at', '<', now()->subDays(7));
        if ($this->connectionId !== null) {
            $expiredMessages->where('gmail_connection_id', $this->connectionId);
        }
        $expiredMessages->orderBy('id')->chunkById(100, function ($messages): void {
            foreach ($messages as $message) {
                if ($message->temporary_file_path) {
                    Storage::disk('local')->delete($message->temporary_file_path);
                }
                $message->update(['temporary_file_path' => null, 'status' => 'needs_setup_expired', 'failure_code' => 'mapping_expired']);
            }
        });
        if (! config('gmail_statement.enabled')) {
            return;
        }
        $connections = GmailConnection::query();
        if ($this->connectionId !== null) {
            $connection = $connections->whereKey($this->connectionId)
                ->whereIn('status', ['connected', 'sync_error'])
                ->first();
            if (! $connection) {
                return;
            }

            $this->syncConnection($connection, $connector);

            return;
        }
        $connections->where('status', 'connected')->orderBy('id')->chunkById(50, function ($connections) use ($connector): void {
            foreach ($connections as $connection) {
                $this->syncConnection($connection, $connector);
            }
        });
    }

    private function syncConnection(GmailConnection $connection, GmailStatementConnector $connector): void
    {
        try {
            $connector->sync($connection);
        } catch (Throwable) {
            $connection->update(['status' => 'sync_error', 'last_sync_status' => 'failed']);
        }
    }
}
