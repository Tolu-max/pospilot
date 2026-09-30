<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\BusinessMembership;
use App\Models\Provider;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_team_members_can_manage_their_own_profile_and_security_sessions(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $owner->id]);
        $manager = User::factory()->create(['email_verified_at' => now()]);
        BusinessMembership::create([
            'agent_profile_id' => $agent->id,
            'user_id' => $manager->id,
            'invited_by' => $owner->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->actingAs($manager)->get('/profile')->assertOk();
        $this->actingAs($manager)->getJson('/api/security/sessions')->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $user->id]);
        Notification::fake();
        $previousEmail = $user->email;

        $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])->assertRedirect();

        $response = $this
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentOnDemand(
            SecurityAlertNotification::class,
            fn (SecurityAlertNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $previousEmail
        );
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])->assertRedirect();

        $response = $this
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])->assertRedirect();

        $response = $this
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])->assertRedirect();

        $response = $this
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_business_export_requires_recent_auth_and_omits_provider_reference_and_unknown_fee_values(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create(['name' => 'Test provider']);
        $terminal = Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id, 'name' => 'Front counter']);
        Transaction::factory()->for($agent)->create([
            'provider_id' => $provider->id,
            'terminal_id' => $terminal->id,
            'external_reference' => 'sensitive-reference-1234',
            'amount' => '10000.00',
            'provider_fee' => '0.00',
            'provider_fee_supplied' => false,
        ]);

        $this->actingAs($user)->getJson('/api/account/export')->assertStatus(423);

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/api/account/export')
            ->assertOk()
            ->assertDownload();
        $export = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('10000.00', $export['transactions'][0]['amount']);
        $this->assertNull($export['transactions'][0]['provider_fee']);
        $this->assertFalse($export['transactions'][0]['provider_fee_known']);
        $this->assertArrayNotHasKey('external_reference', $export['transactions'][0]);
        $this->assertArrayNotHasKey('email', $export['business']);
    }
}
