<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessMembership extends Model
{
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'user_id', 'invited_by', 'role', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function terminals(): BelongsToMany
    {
        return $this->belongsToMany(Terminal::class, 'business_membership_terminal')->withTimestamps();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(BusinessShift::class);
    }
}
