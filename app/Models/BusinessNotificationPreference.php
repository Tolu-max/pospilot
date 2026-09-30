<?php

namespace App\Models;

use Database\Factories\BusinessNotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessNotificationPreference extends Model
{
    /** @use HasFactory<BusinessNotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'agent_profile_id',
        'closing_reminder_enabled',
        'daily_summary_enabled',
        'issue_reminder_enabled',
        'closing_reminder_sent_on',
        'daily_summary_sent_on',
        'issue_reminder_sent_on',
    ];

    protected function casts(): array
    {
        return [
            'closing_reminder_enabled' => 'boolean',
            'daily_summary_enabled' => 'boolean',
            'issue_reminder_enabled' => 'boolean',
            'closing_reminder_sent_on' => 'date',
            'daily_summary_sent_on' => 'date',
            'issue_reminder_sent_on' => 'date',
        ];
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }
}
