<?php

declare(strict_types=1);

// #11070 item 1. A Laravel starter kit ships Route::get('dashboard')->name('dashboard').
// The app's routes load after usarrs', on the same method and URI, so the
// app's replaces usarrs' in the route table and the name dashboard.index goes
// with it. Nothing said why. deck now leaves the dead nav link out; usarrs
// says what happened and what to do.

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Marque\Usarrs\UsarrsServiceProvider;

// Deliberately no refreshNameLookups(). In a real boot the framework refreshes
// them from an app-booted callback registered after usarrs' own, so when the
// check runs the lookup still names usarrs' replaced route: a check that asks
// Route::has() sees nothing wrong.
function takeTheDashboard(): void
{
    Route::get('dashboard', fn () => 'the app\'s own')->name('dashboard');
}

function checkDashboard(): void
{
    (new UsarrsServiceProvider(app()))->reportDashboardCollision();
}

beforeEach(fn () => Cache::flush());

it('logs an error naming the route that took the dashboard, and both ways out', function () {
    Log::spy();
    takeTheDashboard();

    checkDashboard();

    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => str_contains($message, '/dashboard')
        && str_contains($message, "'dashboard'")
        && str_contains($message, 'USARRS_DASHBOARD_PATH')
        && str_contains($message, 'panel'));
});

it('logs it once a day, not on every request', function () {
    Log::spy();
    takeTheDashboard();

    checkDashboard();
    checkDashboard();

    Log::shouldHaveReceived('error')->once();
});

it('says nothing while the dashboard is usarrs\'', function () {
    Log::spy();

    checkDashboard();

    Log::shouldNotHaveReceived('error');
});
