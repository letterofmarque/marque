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
        // deck names the site; `id.app_name` was its key before id became
        // deck (#10839).
        $appName = config('deck.app_name') ?? config('app.name', 'Marque');

        // The link is a token from the default password broker
        // (app('auth.password.broker')), so it lasts what that broker says.
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.expire', 60);

        return (new MailMessage)
            ->subject("Login to {$appName}")
            ->line('Click the button below to log in to your account.')
            ->action('Log In', $this->url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not request this, no action is needed.');
    }
}
