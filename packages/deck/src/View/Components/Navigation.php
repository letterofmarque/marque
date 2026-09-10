<?php

declare(strict_types=1);

namespace Marque\Deck\View\Components;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Marque\Trove\Registry\NavRegistry;

/**
 * Renders the navigation entries packages have registered.
 *
 * This component used to detect its own consumers — a hardcoded `if` per
 * package, each naming a specific frontend package's service provider as a
 * string literal. That inverted the dependency (the shell knowing its tenants),
 * made third-party nav entries impossible, and meant adding an entry required
 * editing and releasing a *different* package than the one gaining it.
 *
 * It now reads `NavRegistry` and renders whatever is there. Packages declare
 * their own entries from their own service providers, so this file names no
 * Marque package at all — see `docs/integration.md` Pattern 4, which flagged
 * exactly this shape.
 */
class Navigation extends Component
{
    public string $appName;

    /**
     * Flattened to primitives deliberately.
     *
     * Livewire serialises public properties into component state, and a NavItem
     * carries a Closure — so handing the objects straight through fails with
     * "Property type not supported in Livewire". Only what the template renders
     * crosses that boundary; the registry keeps the objects.
     *
     * @var list<array{identifier: string, label: string, route: string, icon: string|null}>
     */
    public array $items = [];

    public function mount(): void
    {
        $this->appName = config('deck.app_name', 'Marque');

        // Visibility is per-request and per-user: an entry can be gated on a
        // role, on a query, or on nothing at all. The registry owns that rule;
        // this component only asks.
        $visible = app(NavRegistry::class)->visibleTo(auth()->user());

        $this->items = array_values(array_map(
            fn ($item): array => [
                'identifier' => $item->identifier,
                'label' => $item->label,
                'route' => $item->route,
                'icon' => $item->icon,
            ],
            $visible,
        ));
    }

    public function render(): View
    {
        return view('deck::components.navigation');
    }
}
