<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AccountSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_session_list_marks_current_and_excludes_other_users(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createSession('current-device', $user);
        $this->createSession('other-device', $user);
        $this->createSession('foreign-device', $otherUser);

        $result = app(AccountSessionService::class)->activeSessions($user, 'current-device');

        $this->assertTrue($result['available']);
        $this->assertSame(2, count($result['sessions']));
        $current = collect($result['sessions'])->firstWhere('current', true);
        $this->assertSame(hash('sha256', 'current-device'), $current['id']);
        $this->assertSame('Chrome on Windows', $current['browser']);
        $this->assertArrayNotHasKey('ip_address', $current);
        $this->assertArrayNotHasKey('user_agent', $current);
        $this->assertNotContains(hash('sha256', 'foreign-device'), array_column($result['sessions'], 'id'));
    }

    public function test_revoking_other_sessions_preserves_current_and_foreign_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createSession('current-device', $user);
        $this->createSession('other-device', $user);
        $this->createSession('foreign-device', $otherUser);
        $rememberToken = $user->remember_token;

        $revoked = app(AccountSessionService::class)->revokeOtherSessions($user, 'current-device');

        $this->assertSame(1, $revoked);
        $this->assertDatabaseHas('sessions', ['id' => 'current-device', 'user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-device', 'user_id' => $otherUser->id]);
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
    }

    public function test_revoking_one_hashed_session_only_removes_that_users_other_session(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createSession('current-device', $user);
        $this->createSession('other-device', $user);
        $this->createSession('foreign-device', $otherUser);

        $revoked = app(AccountSessionService::class)->revokeSession($user, hash('sha256', 'other-device'), 'current-device');

        $this->assertTrue($revoked);
        $this->assertDatabaseHas('sessions', ['id' => 'current-device', 'user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-device', 'user_id' => $otherUser->id]);
    }

    private function createSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'payload' => '',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0.0.0 Safari/537.36',
            'last_activity' => now()->timestamp,
        ]);
    }
}
