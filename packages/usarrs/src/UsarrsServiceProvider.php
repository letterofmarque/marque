<?php

declare(strict_types=1);

namespace Marque\Usarrs;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Livewire\Admin\UserIndex;
use Marque\Usarrs\Livewire\Admin\UserShow;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Livewire\Invite\InviteCreate;
use Marque\Usarrs\Livewire\Invite\InviteIndex;
use Marque\Usarrs\Livewire\Profile\Edit;
use Marque\Usarrs\Livewire\Profile\PasskeyManagement;
use Marque\Usarrs\Livewire\Profile\Show;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Policies\InvitePolicy;
use Marque\Usarrs\Policies\UserPolicy;
use Marque\Usarrs\Services\InviteService;

class UsarrsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/usarrs.php', 'usarrs');

        $this->app->bind(InviteServiceInterface::class, InviteService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'usarrs');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerPolicies();

        if (class_exists(\Livewire\Livewire::class)) {
            $this->registerLivewireComponents();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/usarrs.php' => config_path('usarrs.php'),
            ], 'usarrs-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/usarrs'),
            ], 'usarrs-views');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'usarrs-migrations');
        }
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Invite::class, InvitePolicy::class);

        $userModel = config('trove.user_model', 'App\\Models\\User');
        if (class_exists($userModel)) {
            Gate::policy($userModel, UserPolicy::class);
        }
    }

    protected function registerLivewireComponents(): void
    {
        \Livewire\Livewire::component('usarrs-login', Login::class);
        \Livewire\Livewire::component('usarrs-register', Register::class);
        \Livewire\Livewire::component('usarrs-profile-show', Show::class);
        \Livewire\Livewire::component('usarrs-profile-edit', Edit::class);
        \Livewire\Livewire::component('usarrs-passkey-management', PasskeyManagement::class);
        \Livewire\Livewire::component('usarrs-admin-user-index', UserIndex::class);
        \Livewire\Livewire::component('usarrs-admin-user-show', UserShow::class);
        \Livewire\Livewire::component('usarrs-invite-index', InviteIndex::class);
        \Livewire\Livewire::component('usarrs-invite-create', InviteCreate::class);
    }
}
