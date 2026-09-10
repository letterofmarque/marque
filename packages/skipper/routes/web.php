<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Skipper\Http\ScreenController;
use Marque\Skipper\Livewire\Panel;

/*
|--------------------------------------------------------------------------
| Skipper Routes
|--------------------------------------------------------------------------
|
| Two routes, and the shape is deliberate (Build #101 CP1).
|
| The catch-all is registered WITHOUT consulting the registry, so boot order
| cannot affect it — a package whose provider boots after skipper's still gets
| a reachable screen, because resolution happens per request rather than at
| registration time. Registering one route per screen here instead would make
| a late-booting package silently invisible, with no error anywhere.
|
| Per-screen named routes are generated separately, in the provider's
| `booted()` callback, where the registry is fully populated. Consumers get
| `route('admin.taxonomy')` and the routes still survive `route:cache`.
|
*/

Route::middleware(config('skipper.middleware', ['web', 'auth', 'verified']))
    ->prefix(config('skipper.prefix', 'admin'))
    ->group(function () {
        Route::get('/', Panel::class)->name('admin.index');

        // Last, so a generated alias at the same path wins over the catch-all.
        Route::get('{screen}', ScreenController::class)->name('admin.screen');
    });
