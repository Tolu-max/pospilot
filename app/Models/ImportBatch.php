<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasFactory;

    protected $fillable = ['agent_profile_id', 'provider_id', 'filename', 'source', 'entity_type', 'rows_detected', 'rows_imported', 'rows_duplicate', 'rows_failed', 'imported_at', 'status', 'metadata'];

    protected function casts(): array
    {
        return ['status' => ImportBatchStatus::class, 'imported_at' => 'datetime', 'metadata' => 'array'];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }
}
