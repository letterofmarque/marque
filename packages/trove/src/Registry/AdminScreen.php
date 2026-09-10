<?php

declare(strict_types=1);

namespace Marque\Trove\Registry;

use InvalidArgumentException;
use Marque\Trove\Enums\Role;

/**
 * One admin screen a package contributes.
 *
 * Deliberately carries no route *name* — it is derived from the identifier as
 * `admin.<identifier>`. Build #101 CP1 proved the panel registers a single
 * catch-all plus aliases generated in `app()->booted()`, so a package never
 * names its own route and two packages cannot collide on one.
 */
final class AdminScreen
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $label,
        public readonly string $component,
        public readonly string $path,
        public readonly Role $minimumRole = Role::Admin,
        public readonly ?string $icon = null,
        public readonly ?string $group = null,
        public readonly int $position = 100,
    ) {
        if (trim($identifier) === '') {
            throw new InvalidArgumentException('An admin screen identifier cannot be empty.');
        }
    }

    /**
     * The named route the panel generates for this screen.
     */
    public function routeName(): string
    {
        return 'admin.'.$this->identifier;
    }

    /**
     * The screen's path with the panel prefix stripped.
     *
     * The panel routes on this rather than on the identifier, so a package that
     * declares `path: 'admin/users'` is reachable at `/admin/users` and not at
     * `/admin/<identifier>`. Deriving the URL from the identifier instead would
     * give a screen two addresses — one the package bound itself and one the
     * panel invented — and the panel would link to the wrong one.
     */
    public function pathSegment(): string
    {
        $segments = array_values(array_filter(explode('/', trim($this->path, '/'))));

        // A single-segment path is already the segment; anything deeper has the
        // panel prefix on the front.
        return count($segments) > 1
            ? implode('/', array_slice($segments, 1))
            : ($segments[0] ?? $this->identifier);
    }

    /**
     * Whether the given role clears this screen's minimum.
     *
     * Reads trove's existing Role ranking rather than introducing a second
     * permission concept.
     */
    public function allows(Role $role): bool
    {
        return $role->isAtLeast($this->minimumRole);
    }
}
