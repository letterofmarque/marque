<?php

declare(strict_types=1);

namespace Marque\Skipper;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Skipper\Livewire\Panel;

/**
 * The admin panel.
 *
 * skipper depends on trove (for `AdminScreenRegistry`) and deck (for the
 * chrome), and **nothing depends on skipper**. Packages register their admin
 * screens against trove, so they behave identically whether or not the panel is
 * installed — that one-way arrow is what makes third-party screens possible at
 * all, and it only holds because the registry lives in trove rather than here.
 */
class SkipperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/skipper.php', 'skipper');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'skipper');

        if (class_exists(Livewire::class)) {
            Livewire::component('skipper-panel', Panel::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/skipper.php' => config_path('skipper.php'),
            ], 'skipper-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/skipper'),
            ], 'skipper-views');
        }
    }
}
