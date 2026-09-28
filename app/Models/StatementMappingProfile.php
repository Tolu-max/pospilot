<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatementMappingProfile extends Model
{
    protected $fillable = ['agent_profile_id', 'provider_id', 'terminal_id', 'file_type', 'schema_fingerprint', 'match_identifier_fingerprint', 'masked_match_identifier', 'column_mapping', 'status', 'last_successful_use_at'];

    protected function casts(): array
    {
        return ['column_mapping' => 'array', 'last_successful_use_at' => 'datetime'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
