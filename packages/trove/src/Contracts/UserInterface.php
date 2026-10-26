<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Marque\Trove\Enums\Role;

/**
 * Interface for User models that work with Trove.
 */
interface UserInterface extends Authenticatable
{
    /**
     * Admin or above: true for the owner too.
     */
    public function isAdmin(): bool;

    public function isOwner(): bool;

    public function isModerator(): bool;

    public function isUploader(): bool;

    public function hasRoleAtLeast(Role $role): bool;
}
