<?php

declare(strict_types=1);

namespace Marque\Disguise;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Disguise\Livewire\Torrent\Edit;
use Marque\Disguise\Livewire\Torrent\Index;
use Marque\Disguise\Livewire\Torrent\Show;
use Marque\Disguise\Livewire\Torrent\Upload;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;

class DisguiseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/disguise.php', 'disguise');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'disguise');

        if (class_exists(Livewire::class)) {
            $this->registerLivewireComponents();
        }

        $this->registerNavItems();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/disguise.php' => config_path('disguise.php'),
            ], 'disguise-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/disguise'),
            ], 'disguise-views');
        }
    }

    /**
     * Declare disguise's navigation entry.
     *
     * Unlike guise, no visibility rule: disguise is the public frontend and
     * guest browsing is the whole point, so the entry shows to everyone.
     */
    protected function registerNavItems(): void
    {
        $this->app->make(NavRegistry::class)->register(new NavItem(
            identifier: 'disguise-torrents',
            label: 'Torrents',
            route: 'torrents.index',
            icon: 'arrow-down-tray',
            position: 10,
        ));
    }

    protected function registerLivewireComponents(): void
    {
        Livewire::component('disguise-torrent-index', Index::class);
        Livewire::component('disguise-torrent-show', Show::class);
        Livewire::component('disguise-torrent-upload', Upload::class);
        Livewire::component('disguise-torrent-edit', Edit::class);
    }
}
