<?php

declare(strict_types=1);

namespace Marque\SquidInk;

use Illuminate\Support\ServiceProvider;

class SquidInkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/squidink.php', 'squidink');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/squidink.php' => config_path('squidink.php'),
            ], 'squidink-config');
        }
    }
}
