<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityEventRecorder
{
    /** @param array<string, scalar|null> $metadata */
    public function record(?User $user, string $eventType, Request $request, array $metadata = []): void
    {
        $safeMetadata = array_intersect_key($metadata, array_flip(['method', 'provider', 'revoked_sessions']));

        SecurityEvent::query()->create([
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $safeMetadata === [] ? null : $safeMetadata,
            'occurred_at' => now(),
        ]);
    }
}
