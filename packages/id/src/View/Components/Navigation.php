<?php

declare(strict_types=1);

namespace Marque\Id\View\Components;

use Livewire\Component;

/**
 * Dynamic navigation component.
 *
 * Detects which Marque packages are installed and builds
 * the appropriate navigation items.
 */
class Navigation extends Component
{
    public string $appName;

    /** @var array<int, array{label: string, route: string, icon: string}> */
    public array $items = [];

    public function mount(): void
    {
        $this->appName = config('id.app_name', 'Marque');
        $this->items = $this->detectNavItems();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('id::components.navigation');
    }

    /**
     * Detect installed packages and build nav items.
     *
     * @return array<int, array{label: string, route: string, icon: string}>
     */
    private function detectNavItems(): array
    {
        $items = [];

        // Guise (private frontend) - torrents with auth
        if ($this->hasRoute('torrents.index') && $this->hasProvider('Marque\\Guise\\GuiseServiceProvider')) {
            $items[] = [
                'label' => 'Torrents',
                'route' => 'torrents.index',
                'icon' => 'arrow-down-tray',
            ];
        }

        // Disguise (public frontend) - torrents without auth
        if ($this->hasRoute('torrents.index') && $this->hasProvider('Marque\\Disguise\\DisguiseServiceProvider')) {
            $items[] = [
                'label' => 'Torrents',
                'route' => 'torrents.index',
                'icon' => 'arrow-down-tray',
            ];
        }

        // Usarrs (auth/admin) - profile and admin
        if ($this->hasProvider('Marque\\Usarrs\\UsarrsServiceProvider')) {
            if ($this->hasRoute('profile.show')) {
                $items[] = [
                    'label' => 'Profile',
                    'route' => 'profile.show',
                    'icon' => 'user',
                ];
            }

            if ($this->hasRoute('admin.index') && auth()->check()) {
                $user = auth()->user();
                if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
                    $items[] = [
                        'label' => 'Admin',
                        'route' => 'admin.index',
                        'icon' => 'cog-6-tooth',
                    ];
                }
            }
        }

        return $items;
    }

    private function hasRoute(string $name): bool
    {
        return app('router')->has($name);
    }

    private function hasProvider(string $class): bool
    {
        return class_exists($class);
    }
}
