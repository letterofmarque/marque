<?php

declare(strict_types=1);

namespace Marque\Usarrs\Policies;

use Marque\Trove\Contracts\UserInterface;

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
        return $user->isAdmin()
            && $user->getAuthIdentifier() !== $target->getAuthIdentifier();
    }

    public function promote(UserInterface $user, UserInterface $target): bool
    {
        return $user->isAdmin()
            && $user->getAuthIdentifier() !== $target->getAuthIdentifier();
    }
}
