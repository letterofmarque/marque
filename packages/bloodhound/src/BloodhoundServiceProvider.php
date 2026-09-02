<?php

declare(strict_types=1);

namespace Marque\Bloodhound;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Marque\Bloodhound\Contracts\AnnounceLogServiceInterface;
use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Listeners\RecordSnatch;
use Marque\Bloodhound\Services\AntiCheatService;
use Marque\Bloodhound\Services\AnnounceLogService;
use Marque\Bloodhound\Services\AnnounceService;
use Marque\Bloodhound\Services\ClientValidationService;

class BloodhoundServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bloodhound.php', 'bloodhound');

        // Register services as singletons
        $this->app->singleton(ClientValidationService::class);
        $this->app->singleton(AntiCheatService::class);
        $this->app->singleton(AnnounceService::class);

        // Bound to the interface (matching trove's TorrentServiceInterface
        // pattern) so a consumer can swap the query implementation — e.g. to
        // read the log from a warehouse rather than the table itself.
        $this->app->bind(AnnounceLogServiceInterface::class, AnnounceLogService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/tracker.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerEventListeners();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bloodhound.php' => config_path('bloodhound.php'),
            ], 'bloodhound-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'bloodhound-migrations');
        }
    }

    /**
     * Register event listeners.
     */
    protected function registerEventListeners(): void
    {
        Event::listen(TorrentCompleted::class, RecordSnatch::class);
    }
}
