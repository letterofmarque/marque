<?php

declare(strict_types=1);

namespace Marque\Trove;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Marque\Trove\Contracts\TorrentServiceInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Policies\TorrentPolicy;
use Marque\Trove\Services\TorrentService;

class TroveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/trove.php', 'trove');

        $this->app->bind(TorrentServiceInterface::class, TorrentService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerPolicies();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/trove.php' => config_path('trove.php'),
            ], 'trove-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'trove-migrations');
        }
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Torrent::class, TorrentPolicy::class);
    }
}
