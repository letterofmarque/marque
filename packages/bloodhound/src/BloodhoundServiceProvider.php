<?php

declare(strict_types=1);

namespace Marque\Bloodhound;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Marque\Bloodhound\Console\Commands\PruneAnnounceLog;
use Marque\Bloodhound\Console\Commands\SyncSwarmCounts;
use Marque\Bloodhound\Contracts\AnnounceLogServiceInterface;
use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Listeners\RecordSnatch;
use Marque\Bloodhound\Services\AnnounceLogService;
use Marque\Bloodhound\Services\AnnounceService;
use Marque\Bloodhound\Services\AntiCheatService;
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
        $this->registerConsole();

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

    /**
     * Register Artisan commands and their schedules.
     *
     * The prune schedule is registered unconditionally rather than gated on
     * announce_log.enabled: config can change after boot (and an operator who
     * disables logging still wants the existing rows aged out), and the
     * command already no-ops when there's no retention window. A scheduled
     * task that decides at run time is more predictable than one whose
     * existence depends on boot-time config.
     */
    protected function registerConsole(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            PruneAnnounceLog::class,
            SyncSwarmCounts::class,
        ]);

        Schedule::command('bloodhound:prune-announce-log')->daily();

        // Hourly, not daily: this corrects torrents whose peers expired without
        // a stopped announce, and until it runs they show a swarm they no
        // longer have. A day of that is a catalogue full of torrents claiming
        // seeders that left yesterday.
        Schedule::command('bloodhound:sync-swarm-counts')->hourly();
    }
}
