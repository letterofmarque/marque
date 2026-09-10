<?php

declare(strict_types=1);

namespace Marque\Trove\Registry;

use Closure;
use InvalidArgumentException;
use Marque\Trove\Enums\Role;

/**
 * One top-level navigation entry a package contributes.
 *
 * Visibility is an arbitrary closure rather than a role, because the real rules
 * are not all role-shaped: "show Invites only if this user has any" is a query,
 * not a rank comparison. A role gate is the common case, so `forRole()` builds
 * one — but it is a convenience over the closure, not a second mechanism.
 */
final class NavItem
{
    /** @var (Closure(object|null): bool)|null */
    public readonly ?Closure $visible;

    /**
     * @param  (Closure(object|null): bool)|null  $visible  Null means visible to everyone, guests included.
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $label,
        public readonly string $route,
        public readonly ?string $icon = null,
        public readonly int $position = 100,
        ?Closure $visible = null,
    ) {
        if (trim($identifier) === '') {
            throw new InvalidArgumentException('A nav item identifier cannot be empty.');
        }

        $this->visible = $visible;
    }

    /**
     * A nav item gated on a minimum role.
     *
     * Guests never clear a role gate — a null user is denied before the role is
     * ever read, so callers do not each reimplement that check.
     */
    public static function forRole(
        string $identifier,
        string $label,
        string $route,
        Role $minimumRole,
        ?string $icon = null,
        int $position = 100,
    ): self {
        return new self(
            identifier: $identifier,
            label: $label,
            route: $route,
            icon: $icon,
            position: $position,
            visible: function (?object $user) use ($minimumRole): bool {
                if ($user === null || ! method_exists($user, 'hasRoleAtLeast')) {
                    return false;
                }

                return $user->hasRoleAtLeast($minimumRole);
            },
        );
    }

    /**
     * Whether this item should render for the given user (null = guest).
     */
    public function isVisibleTo(?object $user): bool
    {
        if ($this->visible === null) {
            return true;
        }

        return ($this->visible)($user);
    }
}
