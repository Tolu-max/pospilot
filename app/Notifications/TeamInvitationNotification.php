<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $businessName, public string $inviterName, public string $acceptUrl) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Join '.$this->businessName.' on POSPilot')
            ->greeting('You are invited to POSPilot')
            ->line($this->inviterName.' invited you to join '.$this->businessName.' as a POSPilot team member.')
            ->action('Accept invitation', $this->acceptUrl)
            ->line('This invitation expires in 7 days. If you were not expecting it, you can ignore this email.');
    }
}
