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

    /**
     * Its creator, or anyone who could manage the creator (Dan, 2026-10-27).
     *
     * The same rule as banning or changing a role (UserPolicy): admins revoke
     * the invites of users, uploaders and moderators, and only an owner revokes
     * an admin's or an owner's. An invite whose creator is gone is any admin's.
     */
    public function revoke(UserInterface $user, Invite $invite): bool
    {
        if ($user->getAuthIdentifier() === $invite->creator_id) {
            return true;
        }

        $creator = $invite->creator;

        if (! $creator instanceof UserInterface) {
            return $user->isAdmin();
        }

        return (new UserPolicy)->promote($user, $creator);
    }
}
