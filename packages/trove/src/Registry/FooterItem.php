<?php

declare(strict_types=1);

namespace Marque\Trove\Registry;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use InvalidArgumentException;
use Marque\Trove\Enums\Role;

/**
 * One item a package or app contributes to the page footer.
 *
 * The fourth surface registry (#11100). Before it, adding a line to deck's
 * footer meant publishing deck's views and owning a copy of the footer from
 * then on, which silently stays behind whenever deck changes it.
 *
 * It names a Blade view, not a Livewire component like DashboardPanel: the
 * footer is on every page and is usually static text, so a component and its
 * snapshot would cost every page for nothing. A view that wants interactivity
 * can put @livewire inside itself.
 *
 * Unlike the dashboard, the footer shows to guests too, so a null user is a
 * real viewer here: `visible` is asked with null for a guest, and null
 * `visible` means everyone, guests included.
 */
final class FooterItem
{
    /** @var (Closure(object|null): bool)|null */
    public readonly ?Closure $visible;

    /**
     * @param  string  $view  The Blade view that renders this item.
     * @param  (Closure(object|null): bool)|null  $visible  Null means every viewer, guests included.
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $view,
        public readonly int $position = 100,
        ?Closure $visible = null,
    ) {
        if (trim($identifier) === '') {
            throw new InvalidArgumentException('A footer item identifier cannot be empty.');
        }

        if (trim($view) === '') {
            throw new InvalidArgumentException('A footer item must name a view to render.');
        }

        $this->visible = $visible;
    }

    /**
     * An item gated on a minimum role. Guests never clear it, matching
     * NavItem::forRole() and DashboardPanel::forRole().
     */
    public static function forRole(string $identifier, string $view, Role $minimumRole, int $position = 100): self
    {
        return new self(
            identifier: $identifier,
            view: $view,
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
     * An item only a verified user sees, answered as DashboardPanel::verifiedOnly()
     * answers it: a model without MustVerifyEmail passes, guests never do, and
     * `$visible` is asked only once the user has passed.
     *
     * @param  (Closure(object|null): bool)|null  $visible
     */
    public static function verifiedOnly(string $identifier, string $view, int $position = 100, ?Closure $visible = null): self
    {
        return new self(
            identifier: $identifier,
            view: $view,
            position: $position,
            visible: function (?object $user) use ($visible): bool {
                if ($user === null) {
                    return false;
                }

                if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
                    return false;
                }

                return $visible === null || $visible($user);
            },
        );
    }

    /**
     * Whether this item should render for the given viewer (null = guest).
     */
    public function isVisibleTo(?object $user): bool
    {
        if ($this->visible === null) {
            return true;
        }

        return ($this->visible)($user);
    }
}
