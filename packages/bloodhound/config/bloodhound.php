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
    | Announce Log
    |--------------------------------------------------------------------------
    |
    | The ledger. Full-detail history of every announce — every started,
    | regular-interval, completed, and stopped request, with the cumulative
    | totals the client reported, the delta we credited, and the baseline that
    | delta was computed against.
    |
    | ON by default, and this is deliberate (Spec #99, inverting Spec #98).
    | This table is the source of truth for ratio. User and per-torrent totals
    | are projections rebuilt from it, and a wrong number can only be detected
    | — let alone corrected — by comparing it against this. A source of truth
    | cannot be opt-in: an install running without it has no way to know its
    | ratios are wrong, and ratio is what gets people banned.
    |
    | Turning it off is supported, but it disables reconciliation, the rebuild
    | command, and the arithmetic audit along with it. You are choosing to
    | accumulate numbers nothing can verify.
    |
    | 'connection' lets you write this table to a SEPARATE database from the
    | rest of the app — set it to any connection name defined in
    | config/database.php. null (default) uses the app's default connection,
    | same DB as everything else. This is the actual mechanism for isolating
    | a high-write-volume table without Marque needing to know or care what
    | database engine is on the other end.
    |
    | 'retention_days' is null (keep forever) until you set it. This table
    | grows without bound on a busy tracker — set retention_days and
    | bloodhound:prune-announce-log (scheduled) will keep it bounded. Keeping
    | everything stays the default because retention is an operator judgement
    | about storage, not something to guess on their behalf.
    |
    | Note that pruning below the reconciliation watermark destroys the ability
    | to rebuild the totals derived from those rows — the prune command will
    | refuse to do that.
    |
    */

    'announce_log' => [
        'enabled' => env('BLOODHOUND_ANNOUNCE_LOG', true),
        'connection' => env('BLOODHOUND_ANNOUNCE_LOG_CONNECTION'),
        'retention_days' => env('BLOODHOUND_ANNOUNCE_LOG_RETENTION_DAYS'),
    ],
];
