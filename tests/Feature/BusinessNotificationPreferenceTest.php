<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\BusinessNotificationPreference;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BusinessReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BusinessNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_default_off_and_changes_require_recent_authentication(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->getJson('/api/notification-preferences')
            ->assertOk()
            ->assertJsonPath('closing_reminder_enabled', false)
            ->assertJsonPath('daily_summary_enabled', false)
            ->assertJsonPath('issue_reminder_enabled', false);

        $preferences = ['closing_reminder_enabled' => true, 'daily_summary_enabled' => true, 'issue_reminder_enabled' => false];
        $this->putJson('/api/notification-preferences', $preferences)->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->putJson('/api/notification-preferences', $preferences)
            ->assertOk()
            ->assertJson($preferences);
    }

    public function test_daily_email_is_opt_in_idempotent_and_contains_no_financial_values(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $owner->id]);
        $provider = Provider::factory()->create();
        Transaction::factory()->for($agent)->create(['provider_id' => $provider->id, 'amount' => '12450.00', 'transaction_at' => now()]);
        $preference = BusinessNotificationPreference::factory()->create([
            'agent_profile_id' => $agent->id,
            'daily_summary_enabled' => true,
        ]);

        $this->artisan('pospilot:send-business-reminders')->assertSuccessful();
        $this->artisan('pospilot:send-business-reminders')->assertSuccessful();

        Notification::assertSentTo($owner, BusinessReminderNotification::class, function (BusinessReminderNotification $notification, array $channels, object $notifiable): bool {
            $this->assertStringNotContainsString('12450', $notification->intro);

            return $notification->headline === 'Your POSPilot daily overview is ready';
        });
        $this->assertSame(1, Notification::sent($owner, BusinessReminderNotification::class)->count());
        $this->assertSame(today()->toDateString(), $preference->fresh()->daily_summary_sent_on->toDateString());

    }
}
