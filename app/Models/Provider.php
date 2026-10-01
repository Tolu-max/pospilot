<?php

namespace App\Models;

use App\Enums\ProviderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'status'];

    protected function casts(): array
    {
        return ['status' => ProviderStatus::class];
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

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(ProviderConnection::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class);
    }
}
