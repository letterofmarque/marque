<?php

declare(strict_types=1);

namespace Marque\Ise;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Marque\Ise\View\Components\Navigation;

class IseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ise.php', 'ise');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ise');

        Blade::anonymousComponentNamespace(__DIR__.'/../resources/views/components', 'ise');

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('ise-navigation', Navigation::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ise.php' => config_path('ise.php'),
            ], 'ise-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/ise'),
            ], 'ise-views');
        }
    }
}
