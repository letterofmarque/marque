<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Dashboard;

use Illuminate\Contracts\View\View;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Services\InviteService;

/**
 * The dashboard's invites panel: how many the user can still send, how many
 * are outstanding, and the way through to /invites.
 *
 * The allowance is max_per_user less the pending invites — the same count
 * InviteService::canCreateInvite() compares against, so the panel and the
 * create button cannot disagree.
 */
class InvitesPanel extends Component
{
    /**
     * Whether this user has anything here: invites left to send, or some still
     * outstanding. A user with neither sees no panel (Spec #118 criterion 6).
     */
    public static function appliesTo(?object $user): bool
    {
        if ($user === null || ! config('usarrs.invites.enabled', false)) {
            return false;
        }

        $service = app(InviteService::class);

        return $service->canCreateInvite($user) || $service->countActiveForUser($user) > 0;
    }

    public function render(InviteService $service): View
    {
        $user = auth()->user();
        $max = (int) config('usarrs.invites.max_per_user', 3);
        $pending = $service->countActiveForUser($user);

        return view('usarrs::dashboard.panels.invites', [
            'max' => $max,
            'pending' => $pending,
            'available' => max(0, $max - $pending),
            'canCreate' => $service->canCreateInvite($user),
        ]);
    }
}
