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
     * The catch-all registered after them (registerCatchAll) already makes
     * screens reachable; these aliases exist so consumers can write `route('admin.taxonomy')` rather
     * than `route('admin.screen', ['screen' => 'taxonomy'])`.
     */
    protected function registerScreenAliases(): void
    {
        $this->app->booted(function (): void {
            $middleware = config('skipper.middleware', ['web', 'auth', 'verified']);
            $prefix = trim((string) config('skipper.prefix', 'admin'), '/');

            $bound = collect(Route::getRoutes()->getRoutes())
                ->map(fn ($route): string => $route->uri())
                ->all();

            foreach ($this->app->make(AdminScreenRegistry::class)->all() as $screen) {
                $uri = trim($prefix.'/'.$screen->pathSegment(), '/');

                // A package that binds its own route keeps it. usarrs serves
                // `admin/users` as `admin.users.index`, and generating a second
                // route at the same URI silently REPLACED that name — published
                // API disappearing with no error anywhere. The package's own
                // route is the canonical one; the panel just links to it.
                // The screen's own declared path counts too: under a non-default
                // prefix, admin/users bound by usarrs is not prefix/users, and
                // generating that would serve usarrs' screen without usarrs'
                // middleware (#11108).
                if (in_array($uri, $bound, true) || in_array(trim($screen->path, '/'), $bound, true)) {
                    continue;
                }

                Route::middleware($middleware)
                    ->prefix($prefix)
                    ->get($screen->pathSegment(), ScreenController::class)
                    ->defaults('screen', $screen->identifier)
                    ->name($screen->routeName());
            }

            $this->registerCatchAll($middleware, $prefix);
        });
    }

    /**
     * The `admin/{screen}` catch-all, registered last and kept off every path
     * already bound under the prefix (#11108).
     *
     * Laravel answers with the first matching route. Registered in boot(), the
     * catch-all came before any package that loaded its routes later, and
     * answered that package's own path (usarrs' admin/users) itself: skipper's
     * minimumRole ran in place of the package's own middleware and checks.
     * Here every provider's register()/boot() routes and route files are in,
     * and a path someone bound is theirs. A route a provider adds from its own
     * later booted() callback is not seen, and can still lose to this. The
     * controller also refuses a screen whose own path is bound elsewhere, by
     * whatever URL reached it.
     */
    private function registerCatchAll(array $middleware, string $prefix): void
    {
        $taken = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => str_starts_with($uri, $prefix.'/'))
            ->map(fn (string $uri): string => substr($uri, strlen($prefix) + 1))
            ->reject(fn (string $segment): bool => $segment === '' || str_contains($segment, '/') || str_contains($segment, '{'))
            ->unique()
            ->map(fn (string $segment): string => preg_quote($segment, '#'))
            ->values()
            ->all();

        $pattern = $taken === [] ? '[^/]+' : '(?!(?:'.implode('|', $taken).')$)[^/]+';

        Route::middleware($middleware)
            ->prefix($prefix)
            ->get('{screen}', ScreenController::class)
            ->where('screen', $pattern)
            ->name('admin.screen');
    }
}
