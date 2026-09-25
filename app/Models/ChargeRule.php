<?php

namespace App\Models;

use App\Enums\ChargeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargeRule extends Model
{
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'provider_id', 'minimum_amount', 'maximum_amount', 'charge_type', 'charge_value', 'priority', 'active'];

    protected function casts(): array
    {
        return ['charge_type' => ChargeType::class, 'minimum_amount' => 'decimal:2', 'maximum_amount' => 'decimal:2', 'charge_value' => 'decimal:4', 'active' => 'boolean'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
