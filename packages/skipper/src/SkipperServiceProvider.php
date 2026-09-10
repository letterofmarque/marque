<?php

declare(strict_types=1);

namespace Marque\Skipper;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Skipper\Http\ScreenController;
use Marque\Skipper\Livewire\Panel;
use Marque\Trove\Registry\AdminScreenRegistry;

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

        $this->registerScreenAliases();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/skipper.php' => config_path('skipper.php'),
            ], 'skipper-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/skipper'),
            ], 'skipper-views');
        }
    }

    /**
     * Give every registered screen its own named route.
     *
     * Inside `booted()` rather than `boot()`, and that is the whole trick
     * (Build #101 CP1). `boot()` runs per provider in registration order, so a
     * walk of the registry there misses any package that boots after skipper —
     * silently, with no error. `booted()` fires once every provider has booted,
     * so the registry is complete, and it is still early enough for
     * `route:cache` to serialise what it registers.
     *
     * The catch-all in routes/web.php already makes screens reachable; these
     * aliases exist so consumers can write `route('admin.taxonomy')` rather
     * than `route('admin.screen', ['screen' => 'taxonomy'])`.
     */
    protected function registerScreenAliases(): void
    {
        $this->app->booted(function (): void {
            $middleware = config('skipper.middleware', ['web', 'auth', 'verified']);
            $prefix = config('skipper.prefix', 'admin');

            foreach ($this->app->make(AdminScreenRegistry::class)->all() as $screen) {
                Route::middleware($middleware)
                    ->prefix($prefix)
                    ->get($screen->identifier, ScreenController::class)
                    ->defaults('screen', $screen->identifier)
                    ->name($screen->routeName());
            }
        });
    }
}
