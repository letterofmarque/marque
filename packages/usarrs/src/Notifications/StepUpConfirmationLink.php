<?php

declare(strict_types=1);

namespace Marque\Usarrs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Marque\Usarrs\Auth\EmailStepUp;

/**
 * "Confirm it's you": the link a signed-in session asks for before a sensitive
 * action, sent to the account's verified address (Job #355). Approving it on
 * the page it opens confirms the session that asked, wherever this is read.
 *
 * Queued: the session asking waits on the confirm page either way, and a slow
 * mail server shouldn't hold its request open.
 */
class StepUpConfirmationLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $agent,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) config('app.name');

        return (new MailMessage)
            ->subject(__('Confirm it\'s you on :app', ['app' => $app]))
            ->line(__('A browser signed in to your :app account asked to confirm it\'s you, before a change to your account\'s security.', ['app' => $app]))
            ->action(__('Review and confirm'), $this->url)
            ->line(__('This link expires in :minutes minutes.', ['minutes' => EmailStepUp::MINUTES]))
            ->line(__('If you didn\'t ask for this, don\'t confirm. Someone may be signed in as you: tell the site\'s staff.'));
    }
}
