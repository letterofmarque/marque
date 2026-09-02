<?php

declare(strict_types=1);

namespace Marque\Disguise;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Disguise\Livewire\Torrent\Edit;
use Marque\Disguise\Livewire\Torrent\Index;
use Marque\Disguise\Livewire\Torrent\Show;
use Marque\Disguise\Livewire\Torrent\Upload;

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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/disguise.php' => config_path('disguise.php'),
            ], 'disguise-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/disguise'),
            ], 'disguise-views');
        }
    }

    protected function registerLivewireComponents(): void
    {
        Livewire::component('disguise-torrent-index', Index::class);
        Livewire::component('disguise-torrent-show', Show::class);
        Livewire::component('disguise-torrent-upload', Upload::class);
        Livewire::component('disguise-torrent-edit', Edit::class);
    }
}
