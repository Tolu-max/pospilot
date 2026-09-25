<?php

namespace App\Models;

use App\Enums\TransactionAdjustmentDirection;
use App\Enums\TransactionAdjustmentSource;
use App\Enums\TransactionAdjustmentType;
use Database\Factories\TransactionAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionAdjustment extends Model
{
    /** @use HasFactory<TransactionAdjustmentFactory> */
    use HasFactory;

    protected $fillable = ['type', 'amount', 'direction', 'source', 'provider_component_code', 'calculation_rule', 'metadata'];

    protected function casts(): array
    {
        return [
            'type' => TransactionAdjustmentType::class,
            'amount' => 'decimal:2',
            'direction' => TransactionAdjustmentDirection::class,
            'source' => TransactionAdjustmentSource::class,
            'metadata' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
