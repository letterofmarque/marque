<?php

declare(strict_types=1);

namespace Marque\Bosun;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Marque\Bosun\Console\PruneCommand;
use Marque\Bosun\Http\Middleware\StampStaffPresence;
use Marque\Bosun\Store\DatabaseStaffActions;
use Marque\Trove\Contracts\StaffActionsInterface;

class BosunServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bosun.php', 'bosun');

        // bind, not bindIf: trove binds a recorder that does nothing with
        // bindIf, so this wins whichever provider registers first.
        $this->app->bind(StaffActionsInterface::class, DatabaseStaffActions::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bosun');

        $this->app->make(Router::class)->pushMiddlewareToGroup('web', StampStaffPresence::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/bosun.php' => config_path('bosun.php'),
            ], 'bosun-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'bosun-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/bosun'),
            ], 'bosun-views');
        }
    }
}
