<?php

namespace App\Models;

use App\Enums\CustomerChargeSource;
use App\Enums\SettlementStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'provider_id', 'terminal_id', 'import_batch_id', 'external_reference', 'transaction_type', 'amount', 'customer_charge', 'imported_customer_charge', 'calculated_customer_charge', 'customer_charge_override', 'customer_charge_override_by', 'customer_charge_override_at', 'customer_charge_source', 'provider_fee', 'transaction_status', 'settlement_status', 'transaction_at', 'settled_at', 'source', 'import_fingerprint', 'metadata'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'customer_charge' => 'decimal:2', 'imported_customer_charge' => 'decimal:2', 'calculated_customer_charge' => 'decimal:2', 'customer_charge_override' => 'decimal:2', 'customer_charge_override_at' => 'datetime', 'customer_charge_source' => CustomerChargeSource::class, 'provider_fee' => 'decimal:2', 'provider_fee_supplied' => 'boolean', 'provider_fee_components_complete' => 'boolean', 'transaction_status' => TransactionStatus::class, 'settlement_status' => SettlementStatus::class, 'source' => TransactionSource::class, 'transaction_at' => 'datetime', 'settled_at' => 'datetime', 'metadata' => 'array'];
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

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function chargeOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_charge_override_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(TransactionAdjustment::class);
    }
}
