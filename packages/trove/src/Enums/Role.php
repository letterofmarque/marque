<?php

declare(strict_types=1);

namespace Marque\Trove\Enums;

enum Role: string
{
    case User = 'user';
    case Uploader = 'uploader';
    case Moderator = 'moderator';
    case Admin = 'admin';

    /**
     * Get the numeric rank for comparison.
     * Higher rank = more permissions.
     */
    public function rank(): int
    {
        return match ($this) {
            self::User => 0,
            self::Uploader => 1,
            self::Moderator => 2,
            self::Admin => 3,
        };
    }

    /**
     * Check if this role is at least the given role level.
     */
    public function isAtLeast(Role $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    /**
     * Check if this role is higher than the given role.
     */
    public function isHigherThan(Role $role): bool
    {
        return $this->rank() > $role->rank();
    }

    /**
     * Get all roles at or above this level.
     *
     * @return array<Role>
     */
    public static function atLeast(Role $role): array
    {
        return array_filter(
            self::cases(),
            fn (Role $r) => $r->rank() >= $role->rank()
        );
    }
}
