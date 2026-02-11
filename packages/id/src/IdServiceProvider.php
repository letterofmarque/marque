<?php

declare(strict_types=1);

namespace Marque\Id;

use Illuminate\Support\ServiceProvider;
use Marque\Id\View\Components\Navigation;

class IdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/id.php', 'id');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'id');

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('id-navigation', Navigation::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/id.php' => config_path('id.php'),
            ], 'id-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/id'),
            ], 'id-views');
        }
    }
}
