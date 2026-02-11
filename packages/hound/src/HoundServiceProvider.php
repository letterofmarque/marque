<?php

declare(strict_types=1);

namespace Marque\Hound;

use Illuminate\Support\ServiceProvider;
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
        }
    }
}
