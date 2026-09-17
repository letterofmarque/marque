<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Bloodhound\Http\Controllers\AnnounceController;
use Marque\Bloodhound\Http\Controllers\ScrapeController;
use Marque\Bloodhound\Support\AnnounceRouting;
use Marque\Threepio\Http\Middleware\BlockBrowsers;

/*
|--------------------------------------------------------------------------
| Bloodhound Tracker Routes
|--------------------------------------------------------------------------
|
| Announce and scrape endpoints for the BitTorrent tracker.
| These routes bypass most Laravel middleware for performance.
|
| The URL shape is configurable — see `bloodhound.routes` — because a tracker
| migrating onto Marque cannot change the announce URL its existing .torrent
| files point at. Everything about the shape is read from AnnounceRouting so
| the router and the controllers cannot disagree about it.
|
| Route names are fixed: 'tracker.announce' and 'tracker.scrape' regardless of
| the paths, so consumers' route() calls survive a config change.
|
*/

$announcePath = AnnounceRouting::announcePath();
$scrapePath = AnnounceRouting::scrapePath();
$keyParameter = AnnounceRouting::keyParameter();
$keyPattern = AnnounceRouting::keyPattern();

Route::middleware([BlockBrowsers::class])
    ->withoutMiddleware(['web', 'auth', 'csrf'])
    ->group(function () use ($announcePath, $scrapePath, $keyParameter, $keyPattern) {
        if (AnnounceRouting::keyIsInQuery()) {
            // The key rides in the query string (?passkey=...), so the path
            // carries no parameter and the router cannot vet the key. The
            // controllers answer a bad key with a bencoded failure instead —
            // see AnnounceController.
            Route::get($announcePath, AnnounceController::class)
                ->name('tracker.announce');

            Route::get($scrapePath, ScrapeController::class)
                ->name('tracker.scrape');

            return;
        }

        // The key is a path segment, so the route constraint rejects a
        // malformed one with a 404 before any application code runs.
        Route::get($announcePath.'/{'.$keyParameter.'}', AnnounceController::class)
            ->name('tracker.announce')
            ->where($keyParameter, $keyPattern);

        // Scrape's key stays optional — a public scrape is legitimate.
        Route::get($scrapePath.'/{'.$keyParameter.'?}', ScrapeController::class)
            ->name('tracker.scrape')
            ->where($keyParameter, $keyPattern);
    });
