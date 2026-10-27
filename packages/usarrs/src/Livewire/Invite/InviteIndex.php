<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Invite;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\WithPagination;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Services\InviteService;

#[Title('My Invites')]
class InviteIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        abort_unless(config('usarrs.invites.enabled', false), 404);
    }

    public function revoke(int $inviteId, InviteService $service): void
    {
        // InvitePolicy decides, through the service: the creator, or anyone
        // who could manage the creator.
        $service->revoke(Invite::findOrFail($inviteId), actor: auth()->user());
    }

    public function render(InviteService $service): View
    {
        return $this->usarrsView('usarrs::invite.index', [
            'invites' => $service->listForUser(auth()->user()),
            'canCreate' => $service->canCreateInvite(auth()->user()),
        ]);
    }
}
