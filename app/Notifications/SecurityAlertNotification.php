<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $headline,
        public string $intro,
        public string $closingNote,
    ) {}

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
            ->subject($this->headline.' · POSPilot account security')
            ->view(['emails.auth.transactional', 'emails.auth.transactional-text'], [
                'preheader' => 'A security update was made to your POSPilot account.',
                'recipientName' => $notifiable->name ?? 'there',
                'headline' => $this->headline,
                'intro' => $this->intro,
                'actionUrl' => route('login'),
                'actionLabel' => 'Review your account',
                'closingNote' => $this->closingNote,
            ]);
    }
}
