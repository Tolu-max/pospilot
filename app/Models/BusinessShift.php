<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessShift extends Model
{
    protected $fillable = ['agent_profile_id', 'business_membership_id', 'terminal_id', 'started_at', 'ended_at', 'opening_cash', 'closing_cash', 'closing_notes', 'status'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'opening_cash' => 'decimal:2', 'closing_cash' => 'decimal:2'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(BusinessMembership::class, 'business_membership_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(BusinessShiftCashEntry::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(BusinessShiftIssue::class);
    }
}
