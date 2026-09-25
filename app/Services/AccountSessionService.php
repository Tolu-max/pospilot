<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AccountSessionService
{
    /** @return array{available:bool,sessions:list<array<string,mixed>>} */
    public function activeSessions(User $user, string $currentSessionId): array
    {
        if (! $this->usesDatabaseSessions()) {
            return ['available' => false, 'sessions' => []];
        }

        $cutoff = now()->subMinutes((int) config('session.lifetime'))->timestamp;
        $rows = DB::table(config('session.table'))
            ->where('user_id', $user->id)
            ->where(function ($query) use ($cutoff, $currentSessionId): void {
                $query->where('last_activity', '>=', $cutoff)
                    ->orWhere('id', $currentSessionId);
            })
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return [
            'available' => true,
            'sessions' => $rows->map(fn (object $session): array => [
                'id' => hash('sha256', $session->id),
                'current' => hash_equals($currentSessionId, $session->id),
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active_at' => now()->setTimestamp((int) $session->last_activity)->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function revokeOtherSessions(User $user, string $currentSessionId): int
    {
        $this->ensureDatabaseSessions();

        $deleted = DB::table(config('session.table'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return $deleted;
    }

    public function revokeAllSessions(User $user): void
    {
        $this->ensureDatabaseSessions();

        DB::table(config('session.table'))->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }

    private function ensureDatabaseSessions(): void
    {
        if (! $this->usesDatabaseSessions()) {
            throw new RuntimeException('Account session management requires the database session driver.');
        }
    }
}
