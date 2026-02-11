<?php

declare(strict_types=1);

namespace Marque\Usarrs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('id.app_name', config('app.name', 'Marque'));

        return (new MailMessage)
            ->subject("Login to {$appName}")
            ->line('Click the button below to log in to your account.')
            ->action('Log In', $this->url)
            ->line('This link expires in 15 minutes.')
            ->line('If you did not request this, no action is needed.');
    }
}
