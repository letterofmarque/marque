<?php

declare(strict_types=1);

namespace Marque\Hound;

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Marque\Hound\Console\Commands\SyncSwarmCounts;
use Marque\Hound\Services\AnnounceService;

class HoundServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hound.php', 'hound');

        $this->app->singleton(AnnounceService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/tracker.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/hound.php' => config_path('hound.php'),
            ], 'hound-config');

            $this->commands([SyncSwarmCounts::class]);

            // Hourly: until it runs, a torrent whose peers vanished without a
            // stopped announce keeps showing them (#10800).
            Schedule::command('hound:sync-swarm-counts')->hourly();
        }
    }
}
