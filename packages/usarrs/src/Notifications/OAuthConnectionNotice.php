<?php

declare(strict_types=1);

namespace Marque\Usarrs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "<Provider> was connected to (or removed from) your account" (#10858). A
 * connection is a way in, so the member hears about one from their inbox,
 * where a session that isn't theirs can't hide it.
 */
class OAuthConnectionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly bool $connected,
        public readonly ?string $label = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $provider = ucfirst($this->provider);
        $app = (string) config('app.name');

        $message = (new MailMessage)->subject($this->connected
            ? __(':provider was connected to your :app account', ['provider' => $provider, 'app' => $app])
            : __(':provider was removed from your :app account', ['provider' => $provider, 'app' => $app]));

        if ($this->connected) {
            $message->line($this->label
                ? __('The :provider account :label can now sign in to your :app account.', ['provider' => $provider, 'label' => $this->label, 'app' => $app])
                : __('A :provider account can now sign in to your :app account.', ['provider' => $provider, 'app' => $app]));
        } else {
            $message->line(__('Signing in with :provider no longer reaches your :app account.', ['provider' => $provider, 'app' => $app]));
        }

        return $message
            ->action(__('Review your connected accounts'), route('profile.show'))
            ->line(__('If this wasn\'t you, someone may be signed in as you. Remove anything you don\'t recognise from your profile, and tell the site\'s staff.'));
    }
}
