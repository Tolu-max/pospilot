<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessShiftCashEntry extends Model
{
    protected $fillable = ['business_shift_id', 'business_membership_id', 'entry_type', 'amount', 'category', 'description', 'recorded_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'recorded_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(BusinessShift::class, 'business_shift_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(BusinessMembership::class, 'business_membership_id');
    }
}
