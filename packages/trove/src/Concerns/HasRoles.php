<?php

declare(strict_types=1);

namespace Marque\Trove\Concerns;

use Marque\Trove\Enums\Role;

/**
 * Trait for adding role functionality to a User model.
 *
 * The User model must have a 'role' attribute that casts to Role::class.
 */
trait HasRoles
{
    /**
     * Initialize the trait - ensure role is cast properly.
     */
    public function initializeHasRoles(): void
    {
        $this->mergeCasts([
            'role' => Role::class,
        ]);
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Check if user is a moderator or higher.
     */
    public function isModerator(): bool
    {
        return $this->role->isAtLeast(Role::Moderator);
    }

    /**
     * Check if user is an uploader or higher.
     */
    public function isUploader(): bool
    {
        return $this->role->isAtLeast(Role::Uploader);
    }

    /**
     * Check if user's role is at least the given level.
     */
    public function hasRoleAtLeast(Role $role): bool
    {
        return $this->role->isAtLeast($role);
    }
}
