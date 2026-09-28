<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessInvitation extends Model
{
    protected $fillable = ['agent_profile_id', 'invited_by', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
