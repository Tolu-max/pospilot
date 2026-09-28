<?php

namespace App\Jobs;

use App\Models\GmailStatementMessage;
use App\Models\StatementMappingProfile;
use App\Services\GmailStatementImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessGmailStatementMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $messageId, public readonly int $mappingProfileId) {}

    public function handle(GmailStatementImportService $imports): void
    {
        if (! config('gmail_statement.enabled')) {
            return;
        }
        $message = GmailStatementMessage::find($this->messageId);
        $profile = StatementMappingProfile::find($this->mappingProfileId);
        if (! $message || ! $profile || $message->agent_profile_id !== $profile->agent_profile_id || $message->provider_id !== $profile->provider_id) {
            return;
        }

        $imports->process($message, $profile);
    }
}
