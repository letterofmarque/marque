<?php

declare(strict_types=1);

namespace Marque\Trove\Registry;

use Closure;
use InvalidArgumentException;
use Marque\Trove\Enums\Role;

/**
 * One panel a package contributes to the user dashboard.
 *
 * The third instance of the shape Spec #108 established for nav entries and
 * admin screens: a package contributes a surface to a shell it does not own.
 * `docs/integration.md` Pattern 4 sets the promotion trigger at the second
 * instance, so this is not a speculative abstraction — it has two tenants
 * (bloodhound, usarrs) before a line of the dashboard exists.
 *
 * Why this is not just a NavItem with a different label: a nav entry points at
 * a route, where a panel *is* rendered content. It names a Livewire component
 * rather than a route, and several panels render on one page rather than one
 * per page.
 *
 * **Three independent things decide whether a panel appears**, and they are
 * deliberately not one mechanism (Spec #118):
 *
 * 1. the owning package is absent — it never registers, nothing to decide
 * 2. the capability is off — it never registers either, e.g. bloodhound is
 *    installed but `ratio_mode` is `off`, so there is no ratio to show
 * 3. this particular user has nothing to show — the visibility closure declines
 *
 * The first two are registration-time facts and belong in the registering
 * package's `boot()`. Only the third is per-request, and that is what `visible`
 * is for. Conflating them would mean asking a config question on every render.
 */
final class DashboardPanel
{
    /** @var (Closure(object|null): bool)|null */
    public readonly ?Closure $visible;

    /**
     * @param  string  $component  The Livewire component name or class that renders this panel.
     * @param  (Closure(object|null): bool)|null  $visible  Null means visible to every authenticated user.
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $label,
        public readonly string $component,
        public readonly ?string $icon = null,
        public readonly int $position = 100,
        ?Closure $visible = null,
    ) {
        if (trim($identifier) === '') {
            throw new InvalidArgumentException('A dashboard panel identifier cannot be empty.');
        }

        if (trim($component) === '') {
            throw new InvalidArgumentException('A dashboard panel must name a component to render.');
        }

        $this->visible = $visible;
    }

    /**
     * A panel gated on a minimum role.
     *
     * Guests never clear a role gate — a null user is denied before the role is
     * ever read, matching NavItem::forRole() exactly so the two registries do
     * not disagree about what a guest can see.
     */
    public static function forRole(
        string $identifier,
        string $label,
        string $component,
        Role $minimumRole,
        ?string $icon = null,
        int $position = 100,
    ): self {
        return new self(
            identifier: $identifier,
            label: $label,
            component: $component,
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
     * Whether this panel should render for the given user (null = guest).
     */
    public function isVisibleTo(?object $user): bool
    {
        if ($this->visible === null) {
            return true;
        }

        return ($this->visible)($user);
    }
}
