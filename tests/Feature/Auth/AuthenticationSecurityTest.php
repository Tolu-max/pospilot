<?php

namespace Tests\Feature\Auth;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_regenerates_session_and_records_safe_security_events(): void
    {
        $this->get('/register');
        $sessionIdBeforeRegistration = session()->getId();

        $response = $this->post('/register', [
            'name' => 'QA Agent',
            'email' => 'qa.agent@example.test',
            'password' => 'Secure-password-123',
            'password_confirmation' => 'Secure-password-123',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
        $this->assertNotSame($sessionIdBeforeRegistration, session()->getId());
        $this->assertDatabaseHas('security_events', ['event_type' => 'account_registered']);
        $this->assertDatabaseHas('security_events', ['event_type' => 'login']);
        $this->assertSame('password', SecurityEvent::query()->latest('id')->firstOrFail()->metadata['method']);
    }

    public function test_logout_invalidates_authenticated_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event_type' => 'logout']);
    }

    public function test_password_login_regenerates_session_and_records_login(): void
    {
        $user = User::factory()->create();
        $this->get('/login');
        $sessionIdBeforeLogin = session()->getId();

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
        $this->assertNotEmpty(session('auth.password_confirmed_at'));
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event_type' => 'login']);
    }

    public function test_unverified_users_cannot_read_financial_endpoints(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->getJson('/api/financial-summary')
            ->assertForbidden();

        $this->assertDatabaseMissing('security_events', ['event_type' => 'provider_connected']);
    }

    public function test_password_reset_responses_do_not_disclose_account_existence(): void
    {
        Notification::fake();
        $existingUser = User::factory()->create();
        $knownResponse = $this->from('/forgot-password')->post('/forgot-password', ['email' => $existingUser->email]);
        $unknownResponse = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);

        $knownResponse->assertSessionHas('status', 'If an account with that email exists, a password reset link has been sent.');
        $unknownResponse->assertSessionHas('status', 'If an account with that email exists, a password reset link has been sent.');
        Notification::assertSentTo($existingUser, ResetPassword::class);
    }

    public function test_google_can_create_a_verified_local_account_without_persisting_oauth_tokens(): void
    {
        Socialite::fake('google', $this->googleUser('google-subject-100', 'new.agent@example.test'));

        $response = $this->get(route('auth.google.callback', ['state' => 'fake-state', 'code' => 'fake-code']));

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
        $user = User::query()->where('email', 'new.agent@example.test')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('google-subject-100', $user->google_id);
        $this->assertNull($user->password);
        $this->assertArrayNotHasKey('google_id', $user->toArray());
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('token', $user->getAttributes());
        $this->assertSame(['method' => 'google'], $user->securityEvents()->where('event_type', 'login')->firstOrFail()->metadata);
    }

    public function test_google_login_does_not_implicitly_link_an_existing_email_account(): void
    {
        $existingUser = User::factory()->create(['email' => 'existing.agent@example.test']);
        Socialite::fake('google', $this->googleUser('google-subject-200', $existingUser->email));

        $response = $this->get(route('auth.google.callback', ['state' => 'fake-state', 'code' => 'fake-code']));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'Google sign-in could not be completed. Please try again or use email and password.');
        $this->assertGuest();
        $this->assertNull($existingUser->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_identity_can_only_be_linked_to_the_authenticated_matching_verified_account(): void
    {
        config(['services.google' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-client-secret',
            'redirect' => 'https://pospilot.test/auth/google/callback',
        ]]);
        $user = User::factory()->create(['email' => 'linked.agent@example.test']);
        Socialite::fake('google', $this->googleUser('google-subject-300', $user->email));

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('auth.google.link'))
            ->assertRedirect('https://socialite.fake/google/authorize');

        $this->get(route('auth.google.callback', ['state' => 'fake-state', 'code' => 'fake-code']))
            ->assertRedirect(route('profile.edit', absolute: false));

        $this->assertSame('google-subject-300', $user->fresh()->google_id);
        $this->assertDatabaseHas('security_events', [
            'user_id' => $user->id,
            'event_type' => 'google_account_linked',
        ]);
    }

    public function test_google_identity_link_rejects_an_unverified_or_mismatched_email(): void
    {
        config(['services.google' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-client-secret',
            'redirect' => 'https://pospilot.test/auth/google/callback',
        ]]);
        $user = User::factory()->create(['email' => 'owner@example.test']);
        Socialite::fake('google', $this->googleUser('google-subject-301', 'different@example.test'));

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('auth.google.link'))
            ->assertRedirect('https://socialite.fake/google/authorize');

        $this->get(route('auth.google.callback', ['state' => 'fake-state', 'code' => 'fake-code']))
            ->assertRedirect(route('profile.edit', absolute: false));

        $this->assertNull($user->fresh()->google_id);
        $this->assertDatabaseHas('security_events', [
            'user_id' => $user->id,
            'event_type' => 'google_link_rejected',
        ]);
    }

    public function test_google_callback_rejects_missing_oauth_state_without_exposing_exception_details(): void
    {
        config(['services.google' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-client-secret',
            'redirect' => 'https://pospilot.test/auth/google/callback',
        ]]);
        Log::spy();

        $response = $this->get(route('auth.google.callback', ['state' => 'attacker-state', 'code' => 'unused']));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'Google sign-in could not be completed. Please try again or use email and password.');
        $this->assertDatabaseHas('security_events', ['event_type' => 'oauth_state_rejected']);
        Log::shouldNotHaveReceived('error');
    }

    public function test_google_redirect_creates_provider_state(): void
    {
        config(['services.google' => [
            'client_id' => 'test-client',
            'client_secret' => 'test-client-secret',
            'redirect' => 'https://pospilot.test/auth/google/callback',
        ]]);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
        $this->assertNotEmpty(session('state'));
    }

    public function test_sensitive_provider_credential_changes_require_recent_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->putJson('/api/providers/moniepoint/connection', [
                'api_key' => 'fictional-secret',
                'webhook_secret' => 'fictional-webhook-secret',
                'business_id' => '10001',
            ])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Password confirmation required.');
    }

    public function test_session_list_is_scoped_to_the_authenticated_user_and_marks_current_session(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'owner-active-session',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('sessions')->insert([
            'id' => 'foreign-active-session',
            'user_id' => $otherUser->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $response = $this->actingAs($user)->getJson('/api/security/sessions')->assertOk();
        $sessions = $response->json('sessions');

        $this->assertNotEmpty($sessions);
        $this->assertContains(hash('sha256', 'owner-active-session'), array_column($sessions, 'id'));
        $this->assertNotContains(hash('sha256', 'foreign-active-session'), array_column($sessions, 'id'));
        $this->assertArrayNotHasKey('payload', $sessions[0]);
    }

    public function test_revoking_other_sessions_requires_recent_authentication(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $this->actingAs($user)->deleteJson('/api/security/sessions/others')->assertStatus(423);
    }

    public function test_login_attempts_are_rate_limited_without_different_account_errors(): void
    {
        RateLimiter::clear('nonexistent@example.test|127.0.0.1');
        $responses = [];

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $responses[] = $this->from('/login')->post('/login', [
                'email' => 'nonexistent@example.test',
                'password' => 'wrong-password',
            ]);
        }

        $this->assertTrue(RateLimiter::tooManyAttempts('nonexistent@example.test|127.0.0.1', 5));
        $responses[0]->assertSessionHasErrors('email');
        $responses[5]->assertSessionHasErrors('email');
        $events = SecurityEvent::query()->where('event_type', 'login_failed')->get();
        $this->assertCount(5, $events);
        $this->assertSame(['method' => 'password'], $events->firstOrFail()->metadata);
    }

    public function test_sensitive_values_are_excluded_from_user_serialization_and_security_event_metadata(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_id' => 'google-private-subject'])->save();
        app(SecurityEventRecorder::class)->record(
            $user,
            'provider_credentials_updated',
            app(Request::class),
            ['provider' => 'moniepoint', 'api_key' => 'must-not-persist'],
        );

        $this->assertArrayNotHasKey('password', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('google_id', $user->fresh()->toArray());
        $this->assertSame(['provider' => 'moniepoint'], $user->securityEvents()->latest('id')->firstOrFail()->metadata);
    }

    private function googleUser(string $googleId, string $email, bool $emailVerified = true): SocialiteUser
    {
        return SocialiteUser::fake([
            'id' => $googleId,
            'email' => $email,
            'name' => 'Fictional POS Agent',
            'token' => 'oauth-access-token-that-must-not-persist',
            'refreshToken' => 'oauth-refresh-token-that-must-not-persist',
            'verified_email' => $emailVerified,
            'user' => [
                'email_verified' => $emailVerified,
                'verified_email' => $emailVerified,
            ],
        ]);
    }
}
