<?php

declare(strict_types=1);

namespace Marque\Trove\Registry;

use InvalidArgumentException;

/**
 * Where packages declare their top-level navigation entries.
 *
 * Replaces the shell enumerating its consumers. The old approach — a hardcoded
 * `if` per package inside the layout package, naming each service provider as a
 * string literal — meant the shell depended on its own tenants, no third-party
 * package could ever appear in the nav, and adding an entry meant editing and
 * releasing a *different* package. `docs/integration.md` Pattern 4 names that
 * shape as the signal an attachment point belongs one level down.
 *
 * **Evaluated per request, against the current user.** That is the whole
 * difference from AdminScreenRegistry, which must enumerate without one so a
 * route table can be built at boot. Same idea, different lifecycle — which is
 * why they are two classes with no shared parent (Spec #108).
 */
final class NavRegistry
{
    /** @var array<string, NavItem> */
    private array $items = [];

    private bool $sorted = true;

    /**
     * @throws InvalidArgumentException when the identifier is already taken
     */
    public function register(NavItem $item): void
    {
        // Refuse rather than silently replace — see AdminScreenRegistry for why.
        if (isset($this->items[$item->identifier])) {
            throw new InvalidArgumentException(
                "A nav item with identifier [{$item->identifier}] is already registered."
            );
        }

        $this->items[$item->identifier] = $item;
        $this->sorted = false;
    }

    /**
     * Every registered item, ordered, keyed by identifier.
     *
     * @return array<string, NavItem>
     */
    public function all(): array
    {
        $this->sort();

        return $this->items;
    }

    public function find(string $identifier): ?NavItem
    {
        return $this->items[$identifier] ?? null;
    }

    /**
     * The items that should render for this user, ordered. Null means a guest.
     *
     * @return array<string, NavItem>
     */
    public function visibleTo(?object $user): array
    {
        return array_filter(
            $this->all(),
            fn (NavItem $item): bool => $item->isVisibleTo($user),
        );
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        uasort(
            $this->items,
            fn (NavItem $a, NavItem $b): int => [$a->position, $a->label] <=> [$b->position, $b->label],
        );

        $this->sorted = true;
    }
}
