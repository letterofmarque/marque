<?php

declare(strict_types=1);

namespace Marque\Cennad;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CennadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cennad.php', 'cennad');
    }

    public function boot(): void
    {
        $this->registerRateLimiter();

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cennad.php' => config_path('cennad.php'),
            ], 'cennad-config');
        }
    }

    /**
     * The `cennad` limiter that every API route carries.
     *
     * Laravel's `api` middleware group throttles nothing unless the app opts in,
     * so without this the API is unthrottled. Counted per user when signed in, and
     * per IP otherwise, which covers a public catalogue opened to guests. The
     * limit is read per request, and null or 0 turns it off, for an app that
     * throttles the API itself.
     */
    private function registerRateLimiter(): void
    {
        RateLimiter::for('cennad', function (Request $request): Limit {
            $perMinute = (int) config('cennad.rate_limit', 60);

            if ($perMinute <= 0) {
                return Limit::none();
            }

            return Limit::perMinute($perMinute)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });
    }
}
