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
$middleware = config('cennad.middleware', ['api', 'auth:api']);
$routePrefix = config('cennad.route_names.prefix', 'cennad');

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function () use ($routePrefix) {
        Route::apiResource('torrents', TorrentController::class)
            ->only(['index', 'show', 'update', 'destroy'])
            ->names([
                'index' => "{$routePrefix}.torrents.index",
                'show' => "{$routePrefix}.torrents.show",
                'update' => "{$routePrefix}.torrents.update",
                'destroy' => "{$routePrefix}.torrents.destroy",
            ]);
    });
