<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Skipper\Livewire\Panel;

/*
|--------------------------------------------------------------------------
| Skipper Routes
|--------------------------------------------------------------------------
|
| The panel index. The screens' routes are registered in the provider's
| `booted()` callback (Build #101 CP1): the per-screen named aliases, then the
| `admin/{screen}` catch-all, last. By then every provider has loaded its
| routes, so the catch-all can be kept off any path a package bound itself
| (#11108), and a late-booting package still gets a reachable screen, because
| the catch-all resolves screens per request. All of it survives route:cache.
|
*/

Route::middleware(config('skipper.middleware', ['web', 'auth', 'verified']))
    ->prefix(config('skipper.prefix', 'admin'))
    ->group(function () {
        Route::get('/', Panel::class)->name('admin.index');
    });
