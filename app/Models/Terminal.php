<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Terminal extends Model
{
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'provider_id', 'provider_account_id', 'name', 'terminal_identifier', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(BusinessMembership::class, 'business_membership_terminal')->withTimestamps();
    }
}
