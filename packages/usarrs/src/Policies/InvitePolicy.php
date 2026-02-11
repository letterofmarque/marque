<?php

declare(strict_types=1);

namespace Marque\Usarrs\Policies;

use Marque\Trove\Contracts\UserInterface;
use Marque\Usarrs\Models\Invite;

class InvitePolicy
{
    public function viewAny(UserInterface $user): bool
    {
        return true;
    }

    public function create(UserInterface $user): bool
    {
        return config('usarrs.invites.enabled', false);
    }

    public function revoke(UserInterface $user, Invite $invite): bool
    {
        return $user->getAuthIdentifier() === $invite->creator_id
            || $user->isAdmin();
    }
}
