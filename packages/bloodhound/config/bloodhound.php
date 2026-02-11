<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Bloodhound Private Tracker Configuration
    |--------------------------------------------------------------------------
    |
    | Settings specific to the private tracker. Shared protocol settings
    | (announce intervals, peer storage, ports) live in threepio config.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Ratio Tracking
    |--------------------------------------------------------------------------
    |
    | Controls how user ratios are tracked.
    |
    | 'full' - Track upload/download bytes, enforce ratio requirements
    | 'off' - No tracking at all
    | 'seedtime' - Track seeding time instead (ratioless)
    |
    */

    'ratio_mode' => env('BLOODHOUND_RATIO_MODE', 'full'),

    // Minimum ratio required (only applies when ratio_mode = 'full')
    'min_ratio' => env('BLOODHOUND_MIN_RATIO', 0.5),

    // Minimum seed time in seconds (only applies when ratio_mode = 'seedtime')
    'min_seedtime' => env('BLOODHOUND_MIN_SEEDTIME', 86400), // 24 hours

    /*
    |--------------------------------------------------------------------------
    | Client Validation
    |--------------------------------------------------------------------------
    |
    | Control which BitTorrent clients are allowed to use the tracker.
    | mode: 'whitelist' (default) or 'blacklist'
    |
    */

    'client_validation' => [
        'enabled' => env('BLOODHOUND_CLIENT_VALIDATION', true),
        'mode' => env('BLOODHOUND_CLIENT_MODE', 'whitelist'), // 'whitelist' or 'blacklist'
    ],

    // Whitelisted clients (used when mode = 'whitelist')
    // Format: peer_id_prefix => [min_version, max_version, blocked_versions]
    'whitelist' => [
        // qBittorrent: -qB4210- = version 4.2.1.0
        'qBittorrent' => [
            'peer_id_pattern' => '/^-qB(\d)(\d)(\d)(\d)-/',
            'version_format' => '%d.%d.%d.%d',
            'min_version' => '3.3.0.0',
            'max_version' => null,
            'blocked_versions' => [],
        ],
        // Deluge: -DE13F0- = version 1.3.15.0
        'Deluge' => [
            'peer_id_pattern' => '/^-DE(\d)(\d)([0-9A-F])(\d)-/i',
            'version_format' => '%d.%d.%d.%d',
            'min_version' => '1.3.0.0',
            'max_version' => null,
            'blocked_versions' => [],
        ],
        // Transmission: -TR2940- = version 2.94
        'Transmission' => [
            'peer_id_pattern' => '/^-TR(\d)(\d)(\d)(\d)-/',
            'version_format' => '%d.%d%d',
            'min_version' => '2.50',
            'max_version' => null,
            'blocked_versions' => [],
        ],
        // libtorrent (rasterbar): -lt0D60- (used by many clients)
        'libtorrent' => [
            'peer_id_pattern' => '/^-lt([0-9A-F])([0-9A-F])([0-9A-F])([0-9A-F])-/i',
            'version_format' => '%d.%d.%d.%d',
            'min_version' => '0.13.0.0',
            'max_version' => null,
            'blocked_versions' => [],
        ],
        // ruTorrent/rTorrent: -lt0D60- or similar
        'rTorrent' => [
            'peer_id_pattern' => '/^-RT(\d)(\d)(\d)(\d)-/',
            'version_format' => '%d.%d.%d',
            'min_version' => '0.9.0',
            'max_version' => null,
            'blocked_versions' => [],
        ],
        // uTorrent (older but still used): -UT3550-
        'uTorrent' => [
            'peer_id_pattern' => '/^-UT(\d)(\d)(\d)(\d)-/',
            'version_format' => '%d.%d.%d',
            'min_version' => '3.0.0',
            'max_version' => '3.5.5', // newer versions have issues
            'blocked_versions' => ['3.4.2'], // known problematic version
        ],
        // BitTorrent (mainline): -BT7890-
        'BitTorrent' => [
            'peer_id_pattern' => '/^-BT(\d)(\d)(\d)(\d)-/',
            'version_format' => '%d.%d.%d',
            'min_version' => '7.0.0',
            'max_version' => null,
            'blocked_versions' => [],
        ],
        // Vuze/Azureus: -AZ5760-
        'Vuze' => [
            'peer_id_pattern' => '/^-AZ(\d)(\d)(\d)(\d)-/',
            'version_format' => '%d.%d.%d.%d',
            'min_version' => '5.0.0.0',
            'max_version' => null,
            'blocked_versions' => [],
        ],
        // BiglyBT (Vuze fork): -BG2210-
        'BiglyBT' => [
            'peer_id_pattern' => '/^-BG(\d)(\d)(\d)(\d)-/',
            'version_format' => '%d.%d.%d.%d',
            'min_version' => '1.0.0.0',
            'max_version' => null,
            'blocked_versions' => [],
        ],
    ],

    // Blacklisted clients (used when mode = 'blacklist')
    'blacklist' => [
        // Known bad/cheating clients
        'XL0012' => 'Xunlei (Thunder) - known cheater',
        'XF' => 'Xfplay - stat inflation',
        'BT7' => 'BitTorrent 7.x web version - stat issues',
        '-XC' => 'Unknown Chinese client',
        '-SD' => 'Thunder subtool',
        '-GT' => 'Unknown - stat manipulation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-Cheat Configuration
    |--------------------------------------------------------------------------
    */

    'anti_cheat' => [
        'enabled' => env('BLOODHOUND_ANTICHEAT', true),

        // Maximum upload speed in bytes/second (default 100 MB/s - generous for seedboxes)
        'max_upload_speed' => env('BLOODHOUND_MAX_UPLOAD_SPEED', 100 * 1024 * 1024),

        // Maximum download speed in bytes/second (default 100 MB/s)
        'max_download_speed' => env('BLOODHOUND_MAX_DOWNLOAD_SPEED', 100 * 1024 * 1024),

        // Minimum time between announces in seconds (prevent hammering)
        'min_announce_gap' => env('BLOODHOUND_MIN_ANNOUNCE_GAP', 60),

        // Maximum simultaneous connections per user per torrent
        'max_connections_per_torrent' => env('BLOODHOUND_MAX_CONN_TORRENT', 3),

        // Maximum simultaneous connections per IP
        'max_connections_per_ip' => env('BLOODHOUND_MAX_CONN_IP', 10),

        // Block announces from known datacenter/proxy IPs (requires external list)
        'block_datacenter_ips' => env('BLOODHOUND_BLOCK_DC_IPS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stats Queue
    |--------------------------------------------------------------------------
    |
    | User stats updates can be queued to reduce database load.
    |
    */

    'queue' => [
        'enabled' => env('BLOODHOUND_QUEUE_STATS', true),
        'connection' => env('BLOODHOUND_QUEUE_CONNECTION', null), // null = default
        'queue' => env('BLOODHOUND_QUEUE_NAME', 'tracker'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'enabled' => env('BLOODHOUND_LOGGING', false),
        'channel' => env('BLOODHOUND_LOG_CHANNEL', 'stack'),
    ],
];
