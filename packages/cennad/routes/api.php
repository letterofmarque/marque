<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Cennad\Http\Controllers\TorrentController;

/*
|--------------------------------------------------------------------------
| Cennad API Routes
|--------------------------------------------------------------------------
|
| REST API endpoints for Marque.
|
*/

$prefix = config('cennad.prefix', 'api');
$publicMiddleware = config('cennad.public_middleware', ['api']);
$protectedMiddleware = config('cennad.protected_middleware', ['api', 'auth:api']);
$routePrefix = config('cennad.route_names.prefix', 'cennad');

// Public routes - no auth required
Route::prefix($prefix)
    ->middleware($publicMiddleware)
    ->group(function () use ($routePrefix) {
        Route::get('torrents', [TorrentController::class, 'index'])
            ->name("{$routePrefix}.torrents.index");
        Route::get('torrents/{torrent}', [TorrentController::class, 'show'])
            ->name("{$routePrefix}.torrents.show");
    });

// Protected routes - auth required
Route::prefix($prefix)
    ->middleware($protectedMiddleware)
    ->group(function () use ($routePrefix) {
        Route::post('torrents', [TorrentController::class, 'store'])
            ->name("{$routePrefix}.torrents.store");
        Route::put('torrents/{torrent}', [TorrentController::class, 'update'])
            ->name("{$routePrefix}.torrents.update");
        Route::delete('torrents/{torrent}', [TorrentController::class, 'destroy'])
            ->name("{$routePrefix}.torrents.destroy");
    });
