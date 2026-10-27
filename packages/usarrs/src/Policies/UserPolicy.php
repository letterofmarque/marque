<?php

declare(strict_types=1);

namespace Marque\Usarrs\Policies;

use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;

/**
 * Who may see, ban and change the role of whom.
 *
 * Staff are managed from above (trove 5's Owner, Plan #16). Admins manage
 * members, uploaders and moderators. Admins and owners are managed by an owner
 * alone, and granting Admin or Owner is the owner's alone, so no admin can
 * demote or ban another admin, or the owner. Nobody acts on themselves.
 *
 * UserAdminService checks these whenever it's given an actor, so every way
 * in (this screen, an API, a command) gets the same answer. The one rule that
 * isn't about the actor, that the last owner can't be demoted, is the
 * service's.
 */
class UserPolicy
{
    public function viewAny(UserInterface $user): bool
    {
        return $user->isModerator();
    }

    public function view(UserInterface $user, UserInterface $target): bool
    {
        return $user->getAuthIdentifier() === $target->getAuthIdentifier()
            || $user->isModerator();
    }

    public function ban(UserInterface $user, UserInterface $target): bool
    {
        return $this->manages($user, $target);
    }

    public function unban(UserInterface $user, UserInterface $target): bool
    {
        return $this->manages($user, $target);
    }

    /**
     * Whether the user may change the target's role at all.
     */
    public function promote(UserInterface $user, UserInterface $target): bool
    {
        return $this->manages($user, $target);
    }

    /**
     * Whether the user may give the target this role.
     */
    public function setRole(UserInterface $user, UserInterface $target, Role $role): bool
    {
        if (! $this->manages($user, $target)) {
            return false;
        }

        return ! $role->isAtLeast(Role::Admin) || $user->isOwner();
    }

    protected function manages(UserInterface $user, UserInterface $target): bool
    {
        if (! $user->isAdmin() || $user->getAuthIdentifier() === $target->getAuthIdentifier()) {
            return false;
        }

        return ! $target->isAdmin() || $user->isOwner();
    }
}
