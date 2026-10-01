<?php

namespace App\Models;

use Database\Factories\ProviderAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderAccount extends Model
{
    /** @use HasFactory<ProviderAccountFactory> */
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'provider_id', 'display_name', 'account_type', 'identifier_fingerprint', 'masked_identifier'];

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function statementMappingProfiles(): HasMany
    {
        return $this->hasMany(StatementMappingProfile::class);
    }
}
