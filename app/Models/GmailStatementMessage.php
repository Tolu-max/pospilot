<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailStatementMessage extends Model
{
    protected $fillable = ['gmail_connection_id', 'agent_profile_id', 'provider_id', 'statement_mapping_profile_id', 'dedupe_fingerprint', 'attachment_fingerprint', 'processed_message_fingerprint', 'gmail_message_id', 'gmail_attachment_id', 'file_name', 'file_type', 'headers', 'masked_account_identifier', 'status', 'failure_code', 'temporary_file_path', 'rows_imported', 'rows_duplicate', 'rows_failed', 'received_at', 'processed_at'];

    protected $hidden = ['gmail_message_id', 'gmail_attachment_id', 'temporary_file_path'];

    protected function casts(): array
    {
        return ['gmail_message_id' => 'encrypted', 'gmail_attachment_id' => 'encrypted', 'headers' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GmailConnection::class, 'gmail_connection_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function mappingProfile(): BelongsTo
    {
        return $this->belongsTo(StatementMappingProfile::class, 'statement_mapping_profile_id');
    }
}
