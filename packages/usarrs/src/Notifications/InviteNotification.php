<?php

declare(strict_types=1);

namespace Marque\Usarrs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Marque\Usarrs\Models\Invite;

class InviteNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Invite $invite,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('id.app_name', config('app.name', 'Marque'));

        return (new MailMessage)
            ->subject("You've been invited to {$appName}")
            ->line("You've been invited to join {$appName}.")
            ->action('Accept Invite', url('/register?invite='.$this->invite->code))
            ->line('This invite expires '.$this->invite->expires_at->diffForHumans().'.');
    }
}
