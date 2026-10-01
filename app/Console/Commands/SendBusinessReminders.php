<?php

namespace App\Console\Commands;

use App\Models\BusinessNotificationPreference;
use App\Notifications\BusinessReminderNotification;
use App\Services\ReconciliationService;
use App\Services\TransactionalEmailDelivery;
use Illuminate\Console\Command;

class SendBusinessReminders extends Command
{
    protected $signature = 'pospilot:send-business-reminders';

    protected $description = 'Send opted-in business reminder emails without financial details.';

    public function handle(TransactionalEmailDelivery $delivery, ReconciliationService $reconciliation): int
    {
        $sent = 0;
        BusinessNotificationPreference::query()
            ->with('agentProfile.user')
            ->where(function ($query): void {
                $query->where('closing_reminder_enabled', true)
                    ->orWhere('daily_summary_enabled', true)
                    ->orWhere('issue_reminder_enabled', true);
            })
            ->chunkById(100, function ($preferences) use ($delivery, $reconciliation, &$sent): void {
                foreach ($preferences as $preference) {
                    $agent = $preference->agentProfile;
                    $owner = $agent?->user;
                    if (! $agent || ! $owner || ! $owner->hasVerifiedEmail()) {
                        continue;
                    }

                    if ($preference->closing_reminder_enabled
                        && ($preference->closing_reminder_sent_on === null || $preference->closing_reminder_sent_on->lt(today()))
                        && ! $agent->dailyClosings()->whereDate('closing_date', today())->where('status', 'finalized')->exists()) {
                        $delivered = $this->send($delivery, $owner, 'Time to review today’s closing', 'When you are ready, review today’s recorded cash and provider balances in POSPilot.', '/dashboard?screen=closing', 'Review daily closing');
                        if ($delivered) {
                            $preference->update(['closing_reminder_sent_on' => today()]);
                            $sent++;
                        }
                    }

                    $hasActivity = $agent->transactions()->whereDate('transaction_at', today())->exists()
                        || $agent->expenses()->whereDate('expense_date', today())->exists();
                    if ($preference->daily_summary_enabled && $hasActivity
                        && ($preference->daily_summary_sent_on === null || $preference->daily_summary_sent_on->lt(today()))) {
                        $delivered = $this->send($delivery, $owner, 'Your POSPilot daily overview is ready', 'Your daily overview is available in POSPilot. Sign in to review the figures calculated from your records.', '/dashboard', 'Open POSPilot');
                        if ($delivered) {
                            $preference->update(['daily_summary_sent_on' => today()]);
                            $sent++;
                        }
                    }

                    if ($preference->issue_reminder_enabled
                        && ($preference->issue_reminder_sent_on === null || $preference->issue_reminder_sent_on->lt(today()))
                        && $reconciliation->issuesForAgent($agent, today()->subDays(30), today()) !== []) {
                        $delivered = $this->send($delivery, $owner, 'POSPilot items need review', 'Some recent transaction or settlement records still need review. Open POSPilot to see the provider and terminal details.', '/reconciliation', 'Review items');
                        if ($delivered) {
                            $preference->update(['issue_reminder_sent_on' => today()]);
                            $sent++;
                        }
                    }
                }
            });

        $this->info("Sent {$sent} opted-in business reminder(s).");

        return self::SUCCESS;
    }

    private function send(TransactionalEmailDelivery $delivery, object $owner, string $headline, string $intro, string $path, string $actionLabel): bool
    {
        return $delivery->send(
            fn () => $owner->notify(new BusinessReminderNotification($headline, $intro, url($path), $actionLabel)),
            'business_reminder',
        );
    }
}
