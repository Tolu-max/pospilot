<?php

namespace App\Models;

use App\Enums\ProviderConnectionStatus;
use App\Enums\ProviderConnectionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderConnection extends Model
{
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'provider_id', 'connection_type', 'connection_status', 'provider_account_identifier', 'provider_merchant_identifier', 'last_synced_at', 'last_webhook_at', 'last_sync_status', 'last_sync_error', 'metadata'];

    protected $hidden = ['secret_reference'];

    protected function casts(): array
    {
        return ['connection_type' => ProviderConnectionType::class, 'connection_status' => ProviderConnectionStatus::class, 'last_synced_at' => 'datetime', 'last_webhook_at' => 'datetime', 'metadata' => 'array'];
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
