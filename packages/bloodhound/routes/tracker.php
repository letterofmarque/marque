<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Bloodhound\Http\Controllers\AnnounceController;
use Marque\Bloodhound\Http\Controllers\ScrapeController;
use Marque\Bloodhound\Http\Middleware\BlockBrowsers;

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
        // Announce with passkey in URL
        Route::get('announce/{passkey}', AnnounceController::class)
            ->name('tracker.announce')
            ->where('passkey', '[0-9a-zA-Z]{32}');

        // Scrape (passkey optional)
        Route::get('scrape/{passkey?}', ScrapeController::class)
            ->name('tracker.scrape')
            ->where('passkey', '[0-9a-zA-Z]{32}');
    });
