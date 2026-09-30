<?php

namespace App\Models;

use App\Enums\OnboardingState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentProfile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'business_name', 'phone', 'country', 'currency', 'location', 'onboarding_state', 'selected_provider_slugs', 'statement_sender_rules'];

    protected function casts(): array
    {
        return ['onboarding_state' => OnboardingState::class, 'selected_provider_slugs' => 'array', 'statement_sender_rules' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(BusinessShift::class);
    }

    public function providers(): HasManyThrough
    {
        return $this->hasManyThrough(Provider::class, Terminal::class, 'agent_profile_id', 'id', 'id', 'provider_id')->distinct();
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function chargeRules(): HasMany
    {
        return $this->hasMany(ChargeRule::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function providerConnections(): HasMany
    {
        return $this->hasMany(ProviderConnection::class);
    }

    public function dailyClosings(): HasMany
    {
        return $this->hasMany(DailyClosing::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(BusinessNotificationPreference::class);
    }
}
