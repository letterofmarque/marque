<?php

declare(strict_types=1);

namespace Marque\Parley\Policies;

use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;
use Marque\Parley\Models\Thread;

class ThreadPolicy
{
    public function viewAny(UserInterface $user): bool
    {
        return true;
    }

    public function view(UserInterface $user, Thread $thread): bool
    {
        return true;
    }

    /**
     * Forum threads only — a comment thread is created implicitly via
     * HasThreads::comments(), never through this policy.
     */
    public function create(UserInterface $user): bool
    {
        return true;
    }

    public function update(UserInterface $user, Thread $thread): bool
    {
        return $this->owns($user, $thread) || $this->moderates($user);
    }

    public function delete(UserInterface $user, Thread $thread): bool
    {
        return $this->owns($user, $thread) || $this->moderates($user);
    }

    /**
     * Pin, lock and unlock are moderator-only regardless of ownership —
     * unlike edit/delete, there is no "it's my thread" case for these.
     */
    public function pin(UserInterface $user, Thread $thread): bool
    {
        return $this->moderates($user);
    }

    public function lock(UserInterface $user, Thread $thread): bool
    {
        return $this->moderates($user);
    }

    private function owns(UserInterface $user, Thread $thread): bool
    {
        return $user->getAuthIdentifier() === $thread->user_id;
    }

    /**
     * The moderation threshold is configurable (parley.moderation.role,
     * default "moderator") rather than hard-coded to isModerator(), so a
     * deployment can raise the bar to admin-only without a code change.
     */
    private function moderates(UserInterface $user): bool
    {
        $role = Role::from(config('parley.moderation.role', 'moderator'));

        return $user->hasRoleAtLeast($role);
    }
}
