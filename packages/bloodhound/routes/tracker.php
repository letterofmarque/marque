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
| Several shapes can be served at once (Job #109): `bloodhound.routes` is the
| primary endpoint and `bloodhound.endpoints` lists others, all registered
| here together. AnnounceRouting::endpoints() refuses two that share a path.
|
| Route names: 'tracker.announce' and 'tracker.scrape' for the primary
| endpoint whatever its paths, so consumers' route() calls survive a config
| change; 'tracker.announce.<name>' and 'tracker.scrape.<name>' for the others.
|
*/

foreach (AnnounceRouting::endpoints() as $endpoint) {
    $names = AnnounceRouting::routeNames($endpoint);

    // The endpoint's name rides on each route's action, so the controllers can
    // ask which shape the request came in on (AnnounceRouting::forRequest()).
    // Route::group() with the attributes in full, since the fluent registrar
    // takes only the ones Laravel knows ('excluded_middleware' is what
    // withoutMiddleware() sets).
    Route::group([
        'middleware' => [BlockBrowsers::class],
        'excluded_middleware' => ['web', 'auth', 'csrf'],
        AnnounceRouting::ACTION => $endpoint->name,
    ], function () use ($endpoint, $names) {
        $announcePath = $endpoint->announcePath;
        $scrapePath = $endpoint->scrapePath;
        $keyParameter = $endpoint->keyParameter;

        if ($endpoint->keyIsInQuery()) {
            // The key rides in the query string (?passkey=...), so the path
            // carries no parameter and the router cannot vet the key. The
            // controllers answer a bad key with a bencoded failure instead —
            // see AnnounceController.
            Route::get($announcePath, AnnounceController::class)
                ->name($names['announce']);

            Route::get($scrapePath, ScrapeController::class)
                ->name($names['scrape']);

            return;
        }

        // The key is a path segment, so the route constraint vets it before
        // any application code runs.
        Route::get($announcePath.'/{'.$keyParameter.'}', AnnounceController::class)
            ->name($names['announce'])
            ->where($keyParameter, $endpoint->keyPattern);

        // Scrape's key stays optional — a public scrape is legitimate.
        Route::get($scrapePath.'/{'.$keyParameter.'?}', ScrapeController::class)
            ->name($names['scrape'])
            ->where($keyParameter, $endpoint->keyPattern);

        // Anything else under the endpoint's paths: a key that fails the
        // pattern, extra segments, or no key at all. Without these the app's
        // HTML 404 answers, which a client shows as a garbled or unknown error
        // instead of the reason (#11070). They reach the same controllers,
        // whose own check answers a bencoded "Invalid announce key". Registered
        // after the routes above, so a well-formed key never lands here.
        //
        // The default from 9.0. An endpoint's malformed_key = 'not_found'
        // keeps the router's 404, the documented behaviour before it.
        if ($endpoint->malformedKey !== 'failure') {
            return;
        }

        Route::get($announcePath.'/{'.$keyParameter.'?}', AnnounceController::class)
            ->where($keyParameter, '.*');

        Route::get($scrapePath.'/{'.$keyParameter.'}', ScrapeController::class)
            ->where($keyParameter, '.*');
    });
}
