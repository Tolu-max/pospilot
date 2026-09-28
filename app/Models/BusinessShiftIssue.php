<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessShiftIssue extends Model
{
    protected $fillable = ['business_shift_id', 'business_membership_id', 'subject', 'description', 'status', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
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
