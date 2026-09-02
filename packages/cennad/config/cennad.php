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

    // Middleware for read routes (index, show).
    //
    // Defaults to requiring authentication. Cennad cannot tell whether it is
    // serving a private tracker (bloodhound/guise) or a public one
    // (hound/disguise), so it defaults to the safe assumption. A public
    // deployment opts in explicitly by dropping 'auth:api' here.
    'read_middleware' => ['api', 'auth:api'],

    // Middleware for write routes (store, update, destroy)
    'write_middleware' => ['api', 'auth:api'],

    // Route name prefix for API routes
    'route_names' => [
        'prefix' => env('CENNAD_ROUTE_PREFIX', 'cennad'),
        'download' => env('CENNAD_DOWNLOAD_ROUTE', 'torrents.download'),
    ],

    // API rate limiting (requests per minute)
    'rate_limit' => env('CENNAD_RATE_LIMIT', 60),
];
