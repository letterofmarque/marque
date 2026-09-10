<?php

declare(strict_types=1);

namespace Marque\Trove\Registry;

use InvalidArgumentException;
use Marque\Trove\Enums\Role;

/**
 * Where packages declare the admin screens they contribute.
 *
 * Lives in trove — the package every deployment already installs — so a package
 * registers a screen without depending on the admin panel. Install the panel and
 * the screens appear; leave it out and the registering package behaves exactly
 * the same. That is the property that makes third-party extension possible at
 * all, and it only holds because the contract sits here rather than in the panel.
 *
 * **Enumerable without a user**, deliberately. The panel builds its route table
 * from `all()` during `app()->booted()`, long before any request has a user
 * attached. Role filtering is a separate question answered by `visibleTo()` and
 * `allows()` — see the note there about why both exist.
 *
 * No shared base class with NavRegistry, on purpose (Spec #108). The two look
 * similar and behave differently: a nav item is evaluated per-request against an
 * arbitrary rule, a screen is a routing-table entry with a role floor. One
 * abstraction over both would mean a visibility callback invoked in two very
 * different contexts.
 */
final class AdminScreenRegistry
{
    /** @var array<string, AdminScreen> */
    private array $screens = [];

    /**
     * @throws InvalidArgumentException when the identifier is already taken
     */
    public function register(AdminScreen $screen): void
    {
        // Last-write-wins would mean a package silently replacing another
        // package's screen by accident of boot order — the failure mode job
        // #10546 flagged in squidink's shortcode registry, where nothing breaks
        // at boot and something renders wrong months later. Refuse instead.
        if (isset($this->screens[$screen->identifier])) {
            throw new InvalidArgumentException(
                "An admin screen with identifier [{$screen->identifier}] is already registered."
            );
        }

        $this->screens[$screen->identifier] = $screen;
        $this->sorted = false;
    }

    /**
     * Every registered screen, ordered, keyed by identifier.
     *
     * @return array<string, AdminScreen>
     */
    public function all(): array
    {
        $this->sort();

        return $this->screens;
    }

    public function find(string $identifier): ?AdminScreen
    {
        return $this->screens[$identifier] ?? null;
    }

    /**
     * The screens a role may see, ordered, keyed by identifier.
     *
     * @return array<string, AdminScreen>
     */
    public function visibleTo(Role $role): array
    {
        return array_filter(
            $this->all(),
            fn (AdminScreen $screen): bool => $screen->allows($role),
        );
    }

    /**
     * Whether a role may reach one specific screen.
     *
     * The panel filters its navigation with `visibleTo()` and authorises the
     * request with this — both reading the same entry. Filtering a menu is not
     * protecting a screen: without this second check a moderator reaches an
     * admin screen by typing its URL. One source, two enforcement points.
     *
     * An unknown identifier is denied rather than treated as unrestricted.
     */
    public function allows(string $identifier, Role $role): bool
    {
        return $this->find($identifier)?->allows($role) ?? false;
    }

    private bool $sorted = true;

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        uasort(
            $this->screens,
            fn (AdminScreen $a, AdminScreen $b): int => [$a->position, $a->label] <=> [$b->position, $b->label],
        );

        $this->sorted = true;
    }
}
