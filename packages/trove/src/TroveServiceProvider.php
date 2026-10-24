<?php

declare(strict_types=1);

namespace Marque\Trove;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Marque\Trove\Console\Commands\CheckInfoHashes;
use Marque\Trove\Contracts\TorrentServiceInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Policies\TorrentPolicy;
use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Trove\Registry\FooterItemRegistry;
use Marque\Trove\Registry\NavRegistry;
use Marque\Trove\Services\TorrentService;
use Throwable;

class TroveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/trove.php', 'trove');

        $this->app->bind(TorrentServiceInterface::class, TorrentService::class);

        // Singletons because they are shared collection points: every package
        // registers into the same instance, and the panel reads that instance
        // back. A fresh instance per resolution would lose every registration.
        $this->app->singleton(AdminScreenRegistry::class);
        $this->app->singleton(NavRegistry::class);
        $this->app->singleton(DashboardPanelRegistry::class);
        $this->app->singleton(FooterItemRegistry::class);
    }

    public function boot(): void
    {
        $this->reportRemovedConfiguration();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerPolicies();

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckInfoHashes::class,
            ]);

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

    /**
     * Report a stale published config's ratio settings, once a day.
     *
     * trove held the ratio rule before 2.0.0; bloodhound has held it since. A
     * config published before then still sets these, nothing reads them, and
     * nothing said so: twentyt's dashboard judged members against its stale
     * min_ratio while bloodhound held their real standing. So the warning says
     * what is actually in effect, or that no tracker here enforces one.
     *
     * Read as whole arrays and the names built with implode(), as bloodhound's
     * check does, so tools/check-docs doesn't take a dotted string with the
     * key interpolated as a read of every key in both configs.
     */
    public function reportRemovedConfiguration(): void
    {
        $trove = (array) config('trove');
        $bloodhound = config('bloodhound');
        $warnings = [];

        foreach (self::MOVED_TO_BLOODHOUND as $key => $env) {
            if (! Arr::has($trove, $key)) {
                continue;
            }

            $old = implode('.', ['trove', $key]);
            $new = implode('.', ['bloodhound', $key]);
            $configured = json_encode(Arr::get($trove, $key));
            $effective = is_array($bloodhound) && Arr::has($bloodhound, $key)
                ? "the tracker is using {$new} ({$env}), which is ".json_encode(Arr::get($bloodhound, $key))
                : 'no tracker installed here reads a ratio setting';

            $warnings[] = "{$old} was removed in 2.0.0 and is ignored: your config says {$configured}, {$effective}. "
                .'Ratio rules belong to the tracker; delete it from config/trove.php.';
        }

        if ($warnings !== [] && $this->firstToday('trove:removed-config-reported')) {
            foreach ($warnings as $warning) {
                Log::warning($warning);
            }
        }
    }

    /**
     * Settings trove held before 2.0.0, now bloodhound's, with the env var
     * bloodhound reads them from.
     */
    private const MOVED_TO_BLOODHOUND = [
        'ratio_mode' => 'BLOODHOUND_RATIO_MODE',
        'min_ratio' => 'BLOODHOUND_MIN_RATIO',
        'min_seedtime' => 'BLOODHOUND_MIN_SEEDTIME',
    ];

    /**
     * Whether this is the first time today, by the cache. With the cache down,
     * yes: an extra log line beats a boot that fails on a cache outage.
     */
    private function firstToday(string $key): bool
    {
        try {
            return Cache::add($key, true, 86_400);
        } catch (Throwable) {
            return true;
        }
    }
}
