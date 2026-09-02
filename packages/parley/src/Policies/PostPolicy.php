<?php

declare(strict_types=1);

namespace Marque\Parley\Policies;

use Marque\Parley\Models\Post;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;

class PostPolicy
{
    public function view(UserInterface $user, Post $post): bool
    {
        return true;
    }

    /**
     * Authorisation only — whether the THREAD accepts posts right now
     * (locked or not) is a runtime state PostService checks and throws
     * ThreadLockedException for. A policy answers "is this user allowed",
     * not "is this currently possible", so the two stay separate on purpose.
     */
    public function create(UserInterface $user): bool
    {
        return true;
    }

    public function update(UserInterface $user, Post $post): bool
    {
        return $this->owns($user, $post) || $this->moderates($user);
    }

    public function delete(UserInterface $user, Post $post): bool
    {
        return $this->owns($user, $post) || $this->moderates($user);
    }

    private function owns(UserInterface $user, Post $post): bool
    {
        return $user->getAuthIdentifier() === $post->user_id;
    }

    private function moderates(UserInterface $user): bool
    {
        $role = Role::from(config('parley.moderation.role', 'moderator'));

        return $user->hasRoleAtLeast($role);
    }
}
