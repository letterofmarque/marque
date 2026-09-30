<?php

declare(strict_types=1);

namespace Marque\Usarrs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Connect your <provider> account to this account?" — sent to the address an
 * account already has, when an OAuth login arrives unlinked but carrying that
 * address (Spec #142).
 *
 * The provider's say-so about an email is not proof of owning the account it
 * matches. Receiving this mail is: whoever follows the link controls the
 * inbox, which is what signing in as that account has always required.
 */
class OAuthLinkConfirmation extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $url,
        public readonly string $identityLabel,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $provider = ucfirst($this->provider);

        return (new MailMessage)
            ->subject("Connect your {$provider} account")
            ->line("Someone just tried to sign in with the {$provider} account {$this->identityLabel}, which uses this email address.")
            ->line("If that was you and that's your {$provider} account, you can connect it on the next page. Nothing is connected until you confirm there.")
            ->action("Review and connect {$provider}", $this->url)
            ->line('This link expires in 60 minutes.')
            ->line("If it wasn't you, or you don't recognise that {$provider} account, ignore this email. Nothing changes.");
    }
}
