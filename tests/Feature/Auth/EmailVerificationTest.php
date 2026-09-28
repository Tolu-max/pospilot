<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertSame('Email verified. You can now finish setting up your business.', session('inertia.flash_data.toast.message'));
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_email_delivery_failure_returns_a_retry_status(): void
    {
        $user = User::factory()->unverified()->create();
        $this->mock(TransactionalEmailDelivery::class)
            ->shouldReceive('send')
            ->once()
            ->andReturnFalse();

        $this->actingAs($user)
            ->from('/verify-email')
            ->post('/email/verification-notification')
            ->assertRedirect('/verify-email')
            ->assertSessionHas('status', 'verification-link-failed');
    }
}
