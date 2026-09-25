<?php

namespace App\Models;

use App\Enums\ReconciliationOutcome;
use App\Enums\SettlementStatus;
use App\Enums\TransactionSource;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'provider_id', 'terminal_id', 'import_batch_id', 'settlement_reference', 'gross_transaction_amount', 'provider_fee', 'expected_amount', 'actual_amount', 'settlement_date', 'status', 'reconciliation_outcome', 'source', 'import_fingerprint', 'metadata'];

    protected function casts(): array
    {
        return ['gross_transaction_amount' => 'decimal:2', 'provider_fee' => 'decimal:2', 'expected_amount' => 'decimal:2', 'actual_amount' => 'decimal:2', 'settlement_date' => 'date', 'status' => SettlementStatus::class, 'reconciliation_outcome' => ReconciliationOutcome::class, 'source' => TransactionSource::class, 'metadata' => 'array'];
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

    public function discrepancy(): string
    {
        return Money::subtract($this->actual_amount ?? '0', $this->expected_amount ?? '0');
    }
}
