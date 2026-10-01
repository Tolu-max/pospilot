<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public string $headline, public string $intro, public string $actionUrl, public string $actionLabel) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline.' · POSPilot')
            ->view(['emails.auth.transactional', 'emails.auth.transactional-text'], [
                'preheader' => 'A reminder from your POSPilot business workspace.',
                'recipientName' => $notifiable->name ?? 'there',
                'headline' => $this->headline,
                'intro' => $this->intro,
                'actionUrl' => $this->actionUrl,
                'actionLabel' => $this->actionLabel,
                'closingNote' => 'This reminder does not include transaction details, account numbers, or provider credentials.',
            ]);
    }
}
