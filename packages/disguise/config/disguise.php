<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Disguise Public Frontend Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Marque public-facing Livewire frontend.
    | Unlike guise (private tracker frontend), disguise splits routes
    | into public (guest-accessible) and admin (auth-required) groups.
    |
    */

    // Layout to use for full-page Livewire components
    'layout' => env('DISGUISE_LAYOUT', 'id::layouts.app'),

    // Route prefix for web routes
    'prefix' => env('DISGUISE_PREFIX', ''),

    // Middleware for public routes (browse, view, download)
    'public_middleware' => ['web'],

    // Middleware for admin routes (upload, edit)
    'admin_middleware' => ['web', 'auth'],

    // Allow torrent uploads via the web UI
    'allow_upload' => true,

    // Allow torrent downloads via the web UI
    'allow_download' => true,

    // Number of torrents per page
    'per_page' => 25,
];
