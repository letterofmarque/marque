<?php

declare(strict_types=1);

namespace Marque\Guise;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Guise\Livewire\Torrent\Edit;
use Marque\Guise\Livewire\Torrent\Index;
use Marque\Guise\Livewire\Torrent\Show;
use Marque\Guise\Livewire\Torrent\Upload;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;

class GuiseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/guise.php', 'guise');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'guise');

        $this->registerLivewireComponents();
        $this->registerNavItems();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/guise.php' => config_path('guise.php'),
            ], 'guise-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/guise'),
            ], 'guise-views');
        }
    }

    /**
     * Declare guise's navigation entry.
     *
     * The shell used to detect guise and add this itself. Registering it here
     * means guise owns its own entry, and deck never needs to know guise exists.
     */
    protected function registerNavItems(): void
    {
        $this->app->make(NavRegistry::class)->register(new NavItem(
            identifier: 'guise-torrents',
            label: 'Torrents',
            route: 'torrents.index',
            icon: 'arrow-down-tray',
            position: 10,
            // The private frontend's listing sits behind auth, so a guest has
            // nowhere to go if this renders.
            visible: fn (?object $user): bool => $user !== null,
        ));
    }

    protected function registerLivewireComponents(): void
    {
        Livewire::component('guise-torrent-index', Index::class);
        Livewire::component('guise-torrent-show', Show::class);
        Livewire::component('guise-torrent-upload', Upload::class);
        Livewire::component('guise-torrent-edit', Edit::class);
    }
}
