<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionSourceRecord extends Model
{
    protected $fillable = [
        'transaction_id',
        'source_type',
        'source_reference_fingerprint',
        'metadata_fingerprint',
        'observed_at',
    ];

    protected function casts(): array
    {
        return ['observed_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
