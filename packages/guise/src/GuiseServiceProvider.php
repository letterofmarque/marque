<?php

declare(strict_types=1);

namespace Marque\Guise;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Guise\Livewire\Torrent\Edit;
use Marque\Guise\Livewire\Torrent\Index;
use Marque\Guise\Livewire\Torrent\Show;
use Marque\Guise\Livewire\Torrent\Upload;

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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/guise.php' => config_path('guise.php'),
            ], 'guise-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/guise'),
            ], 'guise-views');
        }
    }

    protected function registerLivewireComponents(): void
    {
        Livewire::component('guise-torrent-index', Index::class);
        Livewire::component('guise-torrent-show', Show::class);
        Livewire::component('guise-torrent-upload', Upload::class);
        Livewire::component('guise-torrent-edit', Edit::class);
    }
}
