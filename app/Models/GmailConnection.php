<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GmailConnection extends Model
{
    protected $fillable = ['agent_profile_id', 'gmail_address_masked', 'connected_at', 'disconnected_at', 'provider_rules', 'status', 'last_synced_at', 'last_sync_status', 'last_error_code'];

    protected function casts(): array
    {
        return ['connected_at' => 'datetime', 'disconnected_at' => 'datetime', 'provider_rules' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GmailStatementMessage::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(GmailCredential::class);
    }
}
