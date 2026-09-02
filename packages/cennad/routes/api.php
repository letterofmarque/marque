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

// `public_middleware` / `protected_middleware` were renamed to
// `read_middleware` / `write_middleware` in 4.0. The old keys are still
// honoured — and take precedence — because mergeConfigFrom() injects the new
// keys into every published config, so their presence proves nothing, whereas
// an old key can only be there because someone set it deliberately.
//
// Deliberate is not the same as intended, though: `public_middleware` was the
// key that shipped an unauthenticated read default, so a config carrying it is
// likely reproducing a default nobody chose. It keeps working, but it warns.
// Both old keys are removed in 5.0.
$readMiddleware = config('cennad.public_middleware')
    ?? config('cennad.read_middleware')
    ?? ['api', 'auth:api'];

$writeMiddleware = config('cennad.protected_middleware')
    ?? config('cennad.write_middleware')
    ?? ['api', 'auth:api'];

if (config('cennad.public_middleware') !== null) {
    trigger_error(
        'cennad: config key [public_middleware] is deprecated, use [read_middleware]. '
        .'Note that read endpoints require authentication by default as of 4.0 — if your '
        .'config predates that, your API catalogue may be readable by unauthenticated users.',
        E_USER_DEPRECATED
    );
}

if (config('cennad.protected_middleware') !== null) {
    trigger_error(
        'cennad: config key [protected_middleware] is deprecated, use [write_middleware].',
        E_USER_DEPRECATED
    );
}

$routePrefix = config('cennad.route_names.prefix', 'cennad');

// Read routes - authenticated by default; open them explicitly for a public tracker
Route::prefix($prefix)
    ->middleware($readMiddleware)
    ->group(function () use ($routePrefix) {
        Route::get('torrents', [TorrentController::class, 'index'])
            ->name("{$routePrefix}.torrents.index");
        Route::get('torrents/{torrent}', [TorrentController::class, 'show'])
            ->name("{$routePrefix}.torrents.show");
    });

// Write routes - auth required
Route::prefix($prefix)
    ->middleware($writeMiddleware)
    ->group(function () use ($routePrefix) {
        Route::post('torrents', [TorrentController::class, 'store'])
            ->name("{$routePrefix}.torrents.store");
        Route::put('torrents/{torrent}', [TorrentController::class, 'update'])
            ->name("{$routePrefix}.torrents.update");
        Route::delete('torrents/{torrent}', [TorrentController::class, 'destroy'])
            ->name("{$routePrefix}.torrents.destroy");
    });
