<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Bloodhound\Http\Controllers\AnnounceController;
use Marque\Bloodhound\Http\Controllers\ScrapeController;
use Marque\Threepio\Http\Middleware\BlockBrowsers;

/*
|--------------------------------------------------------------------------
| Bloodhound Tracker Routes
|--------------------------------------------------------------------------
|
| Announce and scrape endpoints for the BitTorrent tracker.
| These routes bypass most Laravel middleware for performance.
|
*/

Route::middleware([BlockBrowsers::class])
    ->withoutMiddleware(['web', 'auth', 'csrf'])
    ->group(function () {
        // Announce with announce key in URL
        Route::get('announce/{announce_key}', AnnounceController::class)
            ->name('tracker.announce')
            ->where('announce_key', '[0-9a-zA-Z]{32}');

        // Scrape (announce key optional)
        Route::get('scrape/{announce_key?}', ScrapeController::class)
            ->name('tracker.scrape')
            ->where('announce_key', '[0-9a-zA-Z]{32}');
    });
