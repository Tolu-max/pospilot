<?php

namespace App\Models;

use App\Enums\DailyClosingStatus;
use App\Enums\DailyClosingVarianceStatus;
use Database\Factories\DailyClosingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyClosing extends Model
{
    /** @use HasFactory<DailyClosingFactory> */
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'closing_date', 'opening_cash', 'entered_closing_cash', 'expected_cash', 'expected_electronic_position', 'actual_electronic_position', 'transaction_volume', 'customer_charges', 'provider_fees', 'expenses', 'total_variance', 'status', 'variance_status', 'notes', 'closed_by', 'closed_at'];

    protected function casts(): array
    {
        return ['closing_date' => 'date', 'opening_cash' => 'decimal:2', 'entered_closing_cash' => 'decimal:2', 'expected_cash' => 'decimal:2', 'expected_electronic_position' => 'decimal:2', 'actual_electronic_position' => 'decimal:2', 'transaction_volume' => 'decimal:2', 'customer_charges' => 'decimal:2', 'provider_fees' => 'decimal:2', 'expenses' => 'decimal:2', 'total_variance' => 'decimal:2', 'status' => DailyClosingStatus::class, 'variance_status' => DailyClosingVarianceStatus::class, 'closed_at' => 'datetime'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function providerBalanceSnapshots(): HasMany
    {
        return $this->hasMany(ProviderBalanceSnapshot::class);
    }
}
