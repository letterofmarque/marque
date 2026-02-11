<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Invite;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
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
    }

    public function create(InviteService $service): void
    {
        $this->validate();

        abort_unless($service->canCreateInvite(auth()->user()), 403, 'Invite limit reached.');

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
