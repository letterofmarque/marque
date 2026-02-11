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
| Open announce and scrape endpoints. No passkey, no auth.
|
*/

Route::middleware([BlockBrowsers::class])
    ->withoutMiddleware(['web', 'auth', 'csrf'])
    ->group(function () {
        Route::get('announce', AnnounceController::class)
            ->name('tracker.announce');

        Route::get('scrape', ScrapeController::class)
            ->name('tracker.scrape');
    });
