<?php

declare(strict_types=1);

namespace Marque\Trove\Registry;

use InvalidArgumentException;

/**
 * Where packages and apps declare what the page footer shows, beside the shell's
 * own lines (#11100).
 *
 * Evaluated per request against the current viewer, like NavRegistry and
 * DashboardPanelRegistry. Registration happens in a provider's `boot()`; the
 * renderer (deck's footer) only asks.
 */
final class FooterItemRegistry
{
    /** @var array<string, FooterItem> */
    private array $items = [];

    private bool $sorted = true;

    /**
     * @throws InvalidArgumentException when the identifier is already taken
     */
    public function register(FooterItem $item): void
    {
        // Refuse rather than silently replace, as every registry does: a
        // replaced item is a contribution that vanished with no error.
        if (isset($this->items[$item->identifier])) {
            throw new InvalidArgumentException(
                "A footer item with identifier [{$item->identifier}] is already registered."
            );
        }

        $this->items[$item->identifier] = $item;
        $this->sorted = false;
    }

    /**
     * Every registered item, ordered, keyed by identifier.
     *
     * @return array<string, FooterItem>
     */
    public function all(): array
    {
        $this->sort();

        return $this->items;
    }

    public function find(string $identifier): ?FooterItem
    {
        return $this->items[$identifier] ?? null;
    }

    /**
     * The items that should render for this viewer, ordered. Null means a guest.
     *
     * @return array<string, FooterItem>
     */
    public function visibleTo(?object $user): array
    {
        return array_filter(
            $this->all(),
            fn (FooterItem $item): bool => $item->isVisibleTo($user),
        );
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        uasort(
            $this->items,
            fn (FooterItem $a, FooterItem $b): int => [$a->position, $a->identifier] <=> [$b->position, $b->identifier],
        );

        $this->sorted = true;
    }
}
