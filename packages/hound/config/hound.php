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
    | REQUIRES REDIS. Peer storage is a real Redis server, not Laravel's cache
    | pointed at one — PeerService uses sets, hashes and atomic counters, none
    | of which the cache abstraction offers. Without a working connection the
    | announce path fatals on the first request. You need ext-redis or
    | predis/predis installed; see packages/threepio/README.md.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Announce Routing
    |--------------------------------------------------------------------------
    |
    | The paths your announce and scrape endpoints live at. These exist for the
    | same reason bloodhound's do: a tracker migrating onto Marque cannot change
    | the URL its existing .torrent files announce to, because those files are
    | already on strangers' disks and cannot be reissued.
    |
    | No key options here — hound is a public tracker and its endpoints take no
    | announce key at all. If you need keys you want bloodhound.
    |
    | Route names stay 'tracker.announce' and 'tracker.scrape' whatever you set.
    |
    */
    'routes' => [
        'announce_path' => env('HOUND_ANNOUNCE_PATH', 'announce'),
        'scrape_path' => env('HOUND_SCRAPE_PATH', 'scrape'),
    ],

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
