<?php

declare(strict_types=1);

namespace Marque\Cennad;

use Illuminate\Support\ServiceProvider;

class CennadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cennad.php', 'cennad');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cennad.php' => config_path('cennad.php'),
            ], 'cennad-config');
        }
    }
}
