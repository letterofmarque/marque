<?php

declare(strict_types=1);

namespace Marque\Usarrs\Policies;

use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Auth\VerifiedAddress;
use Marque\Usarrs\Models\Invite;

class InvitePolicy
{
    public function viewAny(UserInterface $user): bool
    {
        return true;
    }

    /**
     * Invites are how a private tracker controls who joins, so by default only
     * admins and the owner mint them (#11179; nobody had decided that any member could). The
     * floor is `usarrs.invites.min_role`, and an unproven address mints
     * nothing (#10879). This is the one gate: the create page, its action, the
     * button and the dashboard panel all ask it, through
     * InviteService::canCreateInvite().
     */
    public function create(UserInterface $user): bool
    {
        return config('usarrs.invites.enabled', false)
            && $user->hasRoleAtLeast(self::minimumRole())
            && ! VerifiedAddress::missing($user);
    }

    /**
     * The configured floor. A value that isn't a role fails closed: only the
     * owner, rather than guessing what was meant.
     */
    public static function minimumRole(): Role
    {
        return Role::tryFrom((string) config('usarrs.invites.min_role', 'admin')) ?? Role::Owner;
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
