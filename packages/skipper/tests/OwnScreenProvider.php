<?php

declare(strict_types=1);

namespace Marque\Skipper\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;

// A package that binds its own admin route and registers its screen in boot(),
// as usarrs does with admin/users, so skipper's booted() sees both (#11108).
class OwnScreenProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->get('admin/owned', fn () => 'package own route')->name('owned.index');

        $this->app->make(AdminScreenRegistry::class)->register(
            new AdminScreen('vendor-owned', 'Owned', 'skipper-test-screen', 'admin/owned', Role::Admin)
        );
    }
}
