<?php

declare(strict_types=1);

namespace Marque\Skipper\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;

/**
 * The panel index — what an admin lands on.
 *
 * Lists the screens packages have registered, grouped, filtered to what the
 * viewer's role permits. skipper knows nothing about any of them: a screen is
 * whatever a package put in the registry, and installing a new package is the
 * only step needed to make one appear here.
 *
 * Deliberately a screen list rather than a dashboard in v1 (Spec #108 OQ4).
 * Widgets would need a third registry, and that is not earned yet.
 */
class Panel extends Component
{
    public string $title = '';

    /**
     * Registered screens, grouped by their declared group.
     *
     * Flattened to primitives: Livewire serialises public properties into
     * component state, and an AdminScreen carries a Role enum. Only what the
     * template renders crosses that boundary — the same reason deck's
     * Navigation does it (CP #588).
     *
     * @var array<string, list<array{identifier: string, label: string, route: string, icon: string|null}>>
     */
    public array $groups = [];

    public function mount(): void
    {
        $this->title = config('skipper.title', 'Administration');

        $registry = app(AdminScreenRegistry::class);
        $default = config('skipper.default_group', 'General');

        $grouped = [];

        foreach ($registry->visibleTo($this->viewerRole()) as $screen) {
            // A package can register a screen and forget to bind its route.
            // Dropping it here rather than in the template matters: Livewire
            // serialises these properties into a wire:snapshot attribute in the
            // page source, so a screen filtered only at render time would still
            // leak its label to anyone viewing source.
            if (! $this->isRoutable($screen)) {
                continue;
            }

            $grouped[$screen->group ?? $default][] = [
                'identifier' => $screen->identifier,
                'label' => $screen->label,
                'url' => $this->urlFor($screen),
                'icon' => $screen->icon,
            ];
        }

        // The registry already ordered the screens; this only fixes the order
        // the groups themselves appear in, which registration order would
        // otherwise decide arbitrarily.
        ksort($grouped);

        $this->groups = $grouped;
    }

    public function render(): View
    {
        return view('skipper::livewire.panel')
            ->layout(config('skipper.layout', 'deck::layouts.app'));
    }

    /**
     * Whether the screen can be linked at all.
     *
     * One package failing to bind its route should cost that package its tile,
     * not everyone else their admin access.
     */
    private function isRoutable(AdminScreen $screen): bool
    {
        return $this->urlFor($screen) !== null;
    }

    /**
     * Where this screen actually lives.
     *
     * Prefers the panel's generated alias, then the screen's declared path.
     * The second case is the one that matters: a package binding its own route
     * (usarrs serves `admin/users` as `admin.users.index`) gets no generated
     * alias, because generating one at the same URI would replace the
     * package's name. The declared path still reaches it.
     */
    private function urlFor(AdminScreen $screen): ?string
    {
        $router = app('router');

        if ($router->has($screen->routeName())) {
            return route($screen->routeName());
        }

        $uri = '/'.trim($screen->path, '/');

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ('/'.trim($route->uri(), '/') === $uri) {
                return url($uri);
            }
        }

        return null;
    }

    /**
     * The viewing user's role.
     *
     * A user model that does not use trove's HasRoles is treated as the lowest
     * rank rather than crashing — skipper cannot assume the host's model, and
     * an admin panel is the wrong place to fail open.
     */
    private function viewerRole(): Role
    {
        $user = auth()->user();

        if ($user === null) {
            return Role::User;
        }

        $role = $user->role ?? null;

        return $role instanceof Role ? $role : Role::User;
    }
}
