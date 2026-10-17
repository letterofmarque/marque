<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Admin;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Marque\Trove\Support\Seedbox;
use Marque\Usarrs\Livewire\Component;

/**
 * Members' seedboxes, for an admin to approve or revoke.
 *
 * Admin, not moderator: an approved seedbox raises the speed cap its member's
 * announces are held to, which is an anti-cheat decision.
 */
#[Title('Seedboxes')]
class SeedboxIndex extends Component
{
    #[Url]
    public string $status = Seedbox::PENDING;

    public function mount(): void
    {
        abort_unless(config('usarrs.admin.enabled', true), 404);
        abort_unless($this->seedboxes() !== null, 404);
        $this->authorizeAdmin();
    }

    public function approve(int $seedboxId): void
    {
        $this->authorizeAdmin();
        $seedbox = $this->seedboxes()->approve($seedboxId, auth()->user());

        session()->flash('status', __('Approved :network.', ['network' => $seedbox->network]));
    }

    public function revoke(int $seedboxId): void
    {
        $this->authorizeAdmin();
        $seedbox = $this->seedboxes()->revoke($seedboxId, auth()->user());

        session()->flash('status', __('Revoked :network.', ['network' => $seedbox->network]));
    }

    public function render(): View
    {
        $status = in_array($this->status, [Seedbox::PENDING, Seedbox::APPROVED, Seedbox::REVOKED], true) ? $this->status : null;
        $seedboxes = $this->seedboxes()->all($status);

        $userModel = config('trove.user_model', 'App\\Models\\User');
        $members = $userModel::query()
            ->whereIn('id', array_unique(array_map(fn (Seedbox $seedbox) => $seedbox->userId, $seedboxes)))
            ->get()
            ->keyBy('id');

        return $this->usarrsView('usarrs::admin.seedboxes', [
            'seedboxes' => $seedboxes,
            'members' => $members,
            'statuses' => [Seedbox::PENDING, Seedbox::APPROVED, Seedbox::REVOKED],
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }
}
