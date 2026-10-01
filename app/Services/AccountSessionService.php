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
                'browser' => $this->browserLabel($session->user_agent),
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

    public function revokeSession(User $user, string $sessionHash, string $currentSessionId): bool
    {
        $this->ensureDatabaseSessions();

        $sessionIds = DB::table(config('session.table'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->pluck('id');

        foreach ($sessionIds as $sessionId) {
            if (! hash_equals($sessionHash, hash('sha256', $sessionId))) {
                continue;
            }

            return DB::table(config('session.table'))
                ->where('id', $sessionId)
                ->where('user_id', $user->id)
                ->delete() === 1;
        }

        return false;
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

    private function browserLabel(?string $userAgent): string
    {
        $value = strtolower((string) $userAgent);
        $browser = match (true) {
            str_contains($value, 'edg/') => 'Edge',
            str_contains($value, 'opr/') || str_contains($value, 'opera') => 'Opera',
            str_contains($value, 'firefox/') => 'Firefox',
            str_contains($value, 'chrome/') && ! str_contains($value, 'chromium') => 'Chrome',
            str_contains($value, 'safari/') => 'Safari',
            default => 'Unknown browser',
        };
        $platform = match (true) {
            str_contains($value, 'windows') => 'Windows',
            str_contains($value, 'android') => 'Android',
            str_contains($value, 'iphone') || str_contains($value, 'ipad') => 'iOS',
            str_contains($value, 'mac os') || str_contains($value, 'macintosh') => 'macOS',
            str_contains($value, 'linux') => 'Linux',
            default => null,
        };

        return $platform === null ? $browser : $browser.' on '.$platform;
    }

    private function ensureDatabaseSessions(): void
    {
        if (! $this->usesDatabaseSessions()) {
            throw new RuntimeException('Account session management requires the database session driver.');
        }
    }
}
