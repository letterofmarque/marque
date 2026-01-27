<?php

declare(strict_types=1);

namespace Marque\Bloodhound;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Listeners\RecordSnatch;
use Marque\Bloodhound\Services\AntiCheatService;
use Marque\Bloodhound\Services\AnnounceService;
use Marque\Bloodhound\Services\ClientValidationService;
use Marque\Bloodhound\Services\PeerService;

class BloodhoundServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bloodhound.php', 'bloodhound');

        // Register services as singletons
        $this->app->singleton(PeerService::class);
        $this->app->singleton(ClientValidationService::class);
        $this->app->singleton(AntiCheatService::class);
        $this->app->singleton(AnnounceService::class);
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
