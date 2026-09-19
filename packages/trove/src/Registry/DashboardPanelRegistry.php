<?php

declare(strict_types=1);

namespace Marque\Trove\Registry;

use InvalidArgumentException;

/**
 * Where packages declare the panels that make up the user dashboard.
 *
 * The dashboard assembles information five different packages own — ratio and
 * announce key from bloodhound, invites and security posture from usarrs — and
 * without this the page that renders it would have to reach into each of them.
 * That is backwards for the same reason the old hardcoded navigation was: the
 * shell would depend on its own tenants, and no third-party package could ever
 * contribute.
 *
 * **Evaluated per request, against the current user**, like NavRegistry and
 * unlike AdminScreenRegistry — a panel's visibility can be an arbitrary query
 * ("only if this user has invites"), and nothing needs to enumerate panels
 * without a user in order to build a route table.
 *
 * Registration happens in each package's `boot()`. A package whose capability
 * is switched off simply does not register — see DashboardPanel's docblock for
 * why that is kept separate from per-request visibility.
 */
final class DashboardPanelRegistry
{
    /** @var array<string, DashboardPanel> */
    private array $panels = [];

    private bool $sorted = true;

    /**
     * @throws InvalidArgumentException when the identifier is already taken
     */
    public function register(DashboardPanel $panel): void
    {
        // Refuse rather than silently replace. A silently-overwritten panel is
        // a package whose contribution vanished with no error anywhere, which
        // is precisely the failure the registries exist to stop.
        if (isset($this->panels[$panel->identifier])) {
            throw new InvalidArgumentException(
                "A dashboard panel with identifier [{$panel->identifier}] is already registered."
            );
        }

        $this->panels[$panel->identifier] = $panel;
        $this->sorted = false;
    }

    /**
     * Every registered panel, ordered, keyed by identifier.
     *
     * @return array<string, DashboardPanel>
     */
    public function all(): array
    {
        $this->sort();

        return $this->panels;
    }

    public function find(string $identifier): ?DashboardPanel
    {
        return $this->panels[$identifier] ?? null;
    }

    /**
     * The panels that should render for this user, ordered. Null means a guest.
     *
     * @return array<string, DashboardPanel>
     */
    public function visibleTo(?object $user): array
    {
        return array_filter(
            $this->all(),
            fn (DashboardPanel $panel): bool => $panel->isVisibleTo($user),
        );
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        uasort(
            $this->panels,
            fn (DashboardPanel $a, DashboardPanel $b): int => [$a->position, $a->label] <=> [$b->position, $b->label],
        );

        $this->sorted = true;
    }
}
