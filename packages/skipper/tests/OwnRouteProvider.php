<?php

declare(strict_types=1);

namespace Marque\Skipper\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

// A package that binds its own admin route in boot(), the way usarrs binds
// admin/users, and loads after skipper (#11108).
class OwnRouteProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->get('admin/owned', fn () => 'package own route')->name('owned.index');
    }
}
