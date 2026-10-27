<?php

declare(strict_types=1);

namespace Marque\Bosun;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Bosun\Console\PruneCommand;
use Marque\Bosun\Http\Middleware\StampStaffPresence;
use Marque\Bosun\Livewire\AuditLog;
use Marque\Bosun\Store\DatabaseStaffActions;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;

class BosunServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bosun.php', 'bosun');

        // bind, not bindIf: trove binds a recorder that does nothing with
        // bindIf, so this wins whichever provider registers first.
        $this->app->bind(StaffActionsInterface::class, DatabaseStaffActions::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bosun');

        $this->app->make(Router::class)->pushMiddlewareToGroup('web', StampStaffPresence::class);

        Livewire::component('bosun-audit-log', AuditLog::class);

        $this->registerAdminScreens();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/bosun.php' => config_path('bosun.php'),
            ], 'bosun-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'bosun-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/bosun'),
            ], 'bosun-views');
        }
    }

    /**
     * Admins and the owner only: each screen refuses anyone below Admin
     * itself, so a lower floor here would offer a link that then 403s.
     */
    protected function registerAdminScreens(): void
    {
        $registry = $this->app->make(AdminScreenRegistry::class);

        $registry->register(new AdminScreen(
            identifier: 'bosun-audit-log',
            label: 'Audit log',
            component: 'bosun-audit-log',
            path: 'admin/audit-log',
            minimumRole: Role::Admin,
            icon: 'clipboard-document-list',
            group: 'Staff',
            position: 20,
        ));
    }
}
