<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Hound\Http\Controllers\AnnounceController;
use Marque\Hound\Http\Controllers\ScrapeController;
use Marque\Threepio\Http\Middleware\BlockBrowsers;

/*
|--------------------------------------------------------------------------
| Hound Public Tracker Routes
|--------------------------------------------------------------------------
|
| Open announce and scrape endpoints. No announce key, no auth.
|
| The paths are configurable (`hound.routes`) so a public tracker migrating onto
| Marque can keep serving the announce URL its circulating .torrent files point
| at. Route names are fixed regardless: 'tracker.announce', 'tracker.scrape'.
|
*/

$announcePath = trim((string) config('hound.routes.announce_path', 'announce'), '/') ?: 'announce';
$scrapePath = trim((string) config('hound.routes.scrape_path', 'scrape'), '/') ?: 'scrape';

Route::middleware([BlockBrowsers::class])
    ->withoutMiddleware(['web', 'auth', 'csrf'])
    ->group(function () use ($announcePath, $scrapePath) {
        Route::get($announcePath, AnnounceController::class)
            ->name('tracker.announce');

        Route::get($scrapePath, ScrapeController::class)
            ->name('tracker.scrape');
    });
