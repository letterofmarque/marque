<?php

declare(strict_types=1);

namespace Marque\Parley;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Marque\Parley\Contracts\PostServiceInterface;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;
use Marque\Parley\Policies\PostPolicy;
use Marque\Parley\Policies\ThreadPolicy;
use Marque\Parley\Services\PostService;
use Marque\Parley\Services\ThreadService;

class ParleyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/parley.php', 'parley');

        $this->app->bind(ThreadServiceInterface::class, ThreadService::class);
        $this->app->bind(PostServiceInterface::class, PostService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'parley');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Blade::anonymousComponentNamespace(__DIR__.'/../resources/views/components', 'parley');

        $this->registerRoutes();
        $this->registerPolicies();

        // Livewire is how parley's UI is delivered, but the models, services and
        // policies work without it — an API-only consumer can use the package
        // headlessly. Guarded for the same reason every other package in the
        // suite guards it.
        if (class_exists(\Livewire\Livewire::class)) {
            $this->registerLivewireComponents();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/parley.php' => config_path('parley.php'),
            ], 'parley-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/parley'),
            ], 'parley-views');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'parley-migrations');
        }
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Thread::class, ThreadPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
    }

    /**
     * Forum routes are registered only when the forum is switched on, so a
     * comments-only deployment does not expose URLs it has no UI for.
     */
    protected function registerRoutes(): void
    {
        if (config('parley.routes.enabled', true) !== true) {
            return;
        }

        if (config('parley.forum.enabled', true) === true && is_file(__DIR__.'/../routes/forum.php')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/forum.php');
        }
    }

    protected function registerLivewireComponents(): void
    {
        // Registered as the components land.
    }
}
