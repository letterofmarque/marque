<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Hound Public Tracker Configuration
    |--------------------------------------------------------------------------
    |
    | Settings specific to the public tracker. Shared protocol settings
    | (announce intervals, peer storage, ports) live in threepio config.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | IP Limiting
    |--------------------------------------------------------------------------
    |
    | Limit the number of concurrent peers from a single IP address.
    | This is the primary abuse prevention for a public tracker.
    |
    */

    'ip_limiting' => [
        'enabled' => env('HOUND_IP_LIMITING', true),
        'max_per_ip' => env('HOUND_MAX_PER_IP', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'enabled' => env('HOUND_LOGGING', false),
        'channel' => env('HOUND_LOG_CHANNEL', 'stack'),
    ],
];
