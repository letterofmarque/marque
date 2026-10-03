<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Invite;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Auth\VerifiedAddress;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Services\InviteService;

#[Title('Create Invite')]
class InviteCreate extends Component
{
    #[Validate('nullable|email')]
    public string $recipientEmail = '';

    public function mount(): void
    {
        abort_unless(config('usarrs.invites.enabled', false), 404);

        // An unproven address may be a squatter's, and its invites would
        // outlive the owner taking the account back (#10879).
        if (VerifiedAddress::missing(auth()->user())) {
            $this->redirect(route('verification.notice'), navigate: true);
        }
    }

    public function create(InviteService $service): void
    {
        if (VerifiedAddress::missing(auth()->user())) {
            $this->addError('email', __('Verify your email address before creating invites.'));

            return;
        }

        $this->validate();

        abort_unless($service->canCreateInvite(auth()->user()), 403, 'Invite limit reached.');

        // An invite with an address is an email this site sends wherever it's
        // told to, and revoking frees the slot for another. Ten an hour per
        // member (Job #141 review).
        if ($this->recipientEmail !== '' && RateLimiter::hit('usarrs.invite-mail:'.auth()->id(), 3600) > 10) {
            $this->addError('recipientEmail', __('You have sent a lot of invites recently. Try again later.'));

            return;
        }

        $service->create(
            creator: auth()->user(),
            recipientEmail: $this->recipientEmail ?: null,
        );

        session()->flash('status', __('Invite created.'));
        $this->redirect(route('invites.index'), navigate: true);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::invite.create');
    }
}
