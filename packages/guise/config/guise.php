<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Guise Web Frontend Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Marque Livewire web frontend.
    |
    */

    // Layout to use for full-page Livewire components
    'layout' => env('GUISE_LAYOUT', 'id::layouts.app'),

    // Route prefix for web routes
    'prefix' => env('GUISE_PREFIX', ''),

    // Middleware for web routes
    'middleware' => ['web', 'auth', 'verified'],
];
