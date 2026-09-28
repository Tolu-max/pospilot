<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    public function teamMembership(): HasOne
    {
        return $this->hasOne(BusinessMembership::class);
    }

    public function businessAgentProfile(): ?AgentProfile
    {
        $ownedProfile = $this->agentProfile;
        if ($ownedProfile !== null) {
            return $ownedProfile;
        }

        $membership = $this->teamMembership;
        if ($membership === null || ! $membership->is_active) {
            return null;
        }

        return $membership->agentProfile;
    }

    public function businessRole(): ?string
    {
        $agent = $this->businessAgentProfile();
        if ($agent !== null && $agent->user_id === $this->id) {
            return 'owner';
        }

        $membership = $this->teamMembership;
        if ($membership !== null) {
            return $membership->is_active ? $membership->role : null;
        }

        return 'owner';
    }

    /** @return HasMany<SecurityEvent, $this> */
    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
