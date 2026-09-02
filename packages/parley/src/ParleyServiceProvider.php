<?php

declare(strict_types=1);

namespace Marque\Parley;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Parley\Contracts\PostServiceInterface;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Livewire\CommentThread;
use Marque\Parley\Livewire\Forum\CategoryIndex;
use Marque\Parley\Livewire\Forum\ThreadCreate;
use Marque\Parley\Livewire\Forum\ThreadIndex;
use Marque\Parley\Livewire\Forum\ThreadShow;
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
        if (class_exists(Livewire::class)) {
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
        Livewire::component('parley-comment-thread', CommentThread::class);

        // The forum's own full-page components are registered unconditionally
        // here, same as the comment thread — it's route registration
        // (registerRoutes()) that actually gates the forum behind the config
        // toggle. Registering the Livewire components regardless costs
        // nothing (nothing routes to them when the toggle is off) and keeps
        // this method a plain list rather than duplicating the toggle check.
        Livewire::component('parley-forum-category-index', CategoryIndex::class);
        Livewire::component('parley-forum-thread-index', ThreadIndex::class);
        Livewire::component('parley-forum-thread-show', ThreadShow::class);
        Livewire::component('parley-forum-thread-create', ThreadCreate::class);
    }
}
