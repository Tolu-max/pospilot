<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Support\Money;
use Database\Factories\ProviderBalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderBalanceSnapshot extends Model
{
    /** @use HasFactory<ProviderBalanceSnapshotFactory> */
    use HasFactory;

    protected $fillable = ['daily_closing_id', 'provider_id', 'terminal_id', 'expected_balance', 'actual_balance', 'difference', 'source', 'metadata'];

    protected function casts(): array
    {
        return ['expected_balance' => 'decimal:2', 'actual_balance' => 'decimal:2', 'difference' => 'decimal:2', 'source' => TransactionSource::class, 'metadata' => 'array'];
    }

    public function dailyClosing(): BelongsTo
    {
        return $this->belongsTo(DailyClosing::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function calculateDifference(): ?string
    {
        return $this->actual_balance === null ? null : Money::subtract($this->actual_balance, $this->expected_balance);
    }
}
