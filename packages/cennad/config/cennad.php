<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cennad API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Marque REST API.
    |
    */

    // API route prefix (e.g., 'api' results in /api/torrents)
    'prefix' => env('CENNAD_API_PREFIX', 'api'),

    // Middleware for public API routes (index, show)
    'public_middleware' => ['api'],

    // Middleware for protected API routes (store, update, destroy)
    'protected_middleware' => ['api', 'auth:api'],

    // Route name prefix for API routes
    'route_names' => [
        'prefix' => env('CENNAD_ROUTE_PREFIX', 'cennad'),
        'download' => env('CENNAD_DOWNLOAD_ROUTE', 'torrents.download'),
    ],

    // API rate limiting (requests per minute)
    'rate_limit' => env('CENNAD_RATE_LIMIT', 60),
];
