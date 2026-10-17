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
    | REQUIRES REDIS. Peer storage is a real Redis server, not Laravel's cache
    | pointed at one — PeerService uses sets, hashes and atomic counters, none
    | of which the cache abstraction offers. Without a working connection the
    | announce path fatals on the first request. You need ext-redis or
    | predis/predis installed; see packages/threepio/README.md.
    |
    | Note the failure mode if Redis restarts empty: it holds the baseline
    | announce deltas are diffed against, so the next announce credits zero
    | silently. The announce ledger below is what recovers from that.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Announce Routing
    |--------------------------------------------------------------------------
    |
    | The shape of your announce and scrape URLs. These exist because a tracker
    | migrating onto Marque cannot change its announce URL: every .torrent
    | already in circulation announces to the old one, and those files are on
    | strangers' disks. They cannot be reissued.
    |
    | So this is "your URLs, your choice" — not a legacy compatibility mode.
    | There are no per-tracker special cases here and there should never be.
    |
    | 'announce_path'  Path segment, no leading slash. 'announce.php' is fine.
    | 'scrape_path'    Same, for scrape. Change both or you half-migrate.
    | 'key_source'     'path' (/announce/<key>) or 'query' (?passkey=<key>).
    | 'key_parameter'  Route segment name, or query parameter name. TBDev and
    |                  Gazelle-derived trackers use 'passkey'.
    | 'key_pattern'    Regex (no delimiters) the key must match. ONE source of
    |                  truth — the route constraint and the controllers all
    |                  read this, so they cannot drift apart.
    |
    | On key_pattern and security: this gates what reaches a database lookup.
    | The default matches what bloodhound itself mints (Str::random(32), i.e.
    | 32 alphanumeric chars). Widening it is your risk to take — keep it as
    | tight as your existing keys allow, and anchor nothing yourself; the
    | pattern is anchored for you.
    |
    | On key_source and error surfaces: with the key in the path, a malformed
    | key is a 404 from the router and never reaches PHP. As a query parameter
    | every request reaches the controller, which answers a malformed key with
    | a bencoded 'failure reason' and HTTP 200 — the same shape as every other
    | tracker error, because that is what BitTorrent clients expect. Trackers
    | are probed constantly; know which of the two you are running.
    |
    | Route NAMES never change: 'tracker.announce' and 'tracker.scrape'
    | whatever you set here, so route() calls keep working.
    |
    */

    'routes' => [
        'announce_path' => env('BLOODHOUND_ANNOUNCE_PATH', 'announce'),
        'scrape_path' => env('BLOODHOUND_SCRAPE_PATH', 'scrape'),
        'key_source' => env('BLOODHOUND_KEY_SOURCE', 'path'),
        'key_parameter' => env('BLOODHOUND_KEY_PARAMETER', 'announce_key'),
        'key_pattern' => env('BLOODHOUND_KEY_PATTERN', '[0-9a-zA-Z]{32}'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | 'private_flag' is what an uploaded torrent's private flag must be:
    | 'require' (the default: refuse a torrent that isn't private), 'disallow',
    | 'warn_if_public', 'warn_if_private' or 'allow'. The flag is inside the
    | info dictionary, so Marque never sets it for the uploader. Under
    | 'require', a refused upload tells them to recreate the torrent with
    | "private" ticked. Without the flag, clients share peers over DHT and
    | peer exchange, outside the tracker. An unrecognised value is treated
    | as 'require'.
    |
    */

    'uploads' => [
        'private_flag' => env('BLOODHOUND_PRIVATE_FLAG', 'require'),
    ],

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

    // check-docs: ignore — documented but not yet enforced, read by nothing until #10732
    'ratio_mode' => env('BLOODHOUND_RATIO_MODE', 'full'),

    // Minimum ratio required (only applies when ratio_mode = 'full')
    // check-docs: ignore — not yet enforced, read by nothing until #10732
    'min_ratio' => env('BLOODHOUND_MIN_RATIO', 0.5),

    // Minimum seed time in seconds (only applies when ratio_mode = 'seedtime')
    // check-docs: ignore — not yet enforced, read by nothing until #10732
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

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | Every announce is credited against the peer's last counters, kept in
    | Redis, so Redis must keep what it holds: appendonly yes and
    | maxmemory-policy noeviction, on a database of its own. In production
    | announces are refused until it does; `php artisan bloodhound:check-redis`
    | says what to change. If your provider makes the data durable another
    | way (replication, snapshots) and offers no AOF, set durability to
    | 'attested'; the eviction policy is still checked.
    |
    */

    'redis' => [
        'durability' => env('BLOODHOUND_REDIS_DURABILITY', 'required'),
    ],

    // Seconds an expired peer's record is kept as its baseline after its last
    // announce, so a member back from a crash without having sent `stopped`
    // is credited what they moved in between.
    'baseline_retention' => env('BLOODHOUND_BASELINE_RETENTION', 7 * 86_400),

    'anti_cheat' => [
        'enabled' => env('BLOODHOUND_ANTICHEAT', true),

        // The speed cap for ordinary peers, in bytes/second, per direction.
        // Bytes claimed over it in one interval are forfeited: not credited,
        // and the baseline moves on so they never land later. Lower it to
        // what your members' connections actually do; seedboxes have their
        // own cap below. (100 MB/s is about 800 Mbit/s.)
        'max_upload_speed' => env('BLOODHOUND_MAX_UPLOAD_SPEED', 100 * 1024 * 1024),
        'max_download_speed' => env('BLOODHOUND_MAX_DOWNLOAD_SPEED', 100 * 1024 * 1024),

        // The cap for announces from a member's approved seedbox.
        // 1,250,000,000 bytes/second is 10 Gbit/s.
        // Members register seedboxes (an address, or a provider's range);
        // with require_approval an admin approves each one before its cap
        // applies. A range may be at most a /24 (IPv4) or a /48 (IPv6), so it
        // cannot take in other customers of the same provider.
        'seedbox' => [
            'max_upload_speed' => env('BLOODHOUND_SEEDBOX_MAX_UPLOAD_SPEED', 1_250_000_000),
            'max_download_speed' => env('BLOODHOUND_SEEDBOX_MAX_DOWNLOAD_SPEED', 1_250_000_000),
            'require_approval' => env('BLOODHOUND_SEEDBOX_REQUIRE_APPROVAL', true),
            'min_prefix_v4' => 24,
            'min_prefix_v6' => 48,
        ],

        // Minimum seconds between two regular announces from one peer (a
        // hammer guard). started, completed and stopped are never refused for
        // timing.
        'min_announce_gap' => env('BLOODHOUND_MIN_ANNOUNCE_GAP', 60),

        // Locations a member may seed and leech one torrent from at once. A
        // location is an IP address (an IPv6 /64). Only a NEW location is
        // refused: a client already in the swarm is never cut off by a count.
        // Leeching from more than one place is how one account is shared.
        'max_seeding_locations_per_torrent' => env('BLOODHOUND_MAX_SEEDING_LOCATIONS', 3),
        'max_leeching_locations_per_torrent' => env('BLOODHOUND_MAX_LEECHING_LOCATIONS', 1),

        // Live sessions (one torrent, one peer ID) from one IP. A seedbox
        // running hundreds of torrents from one address needs this raised.
        'max_connections_per_ip' => env('BLOODHOUND_MAX_CONN_IP', 10),

        // Block announces from known datacenter/proxy IPs (requires external list)
        // check-docs: ignore — never built, needs a design decision first (#10813)
        'block_datacenter_ips' => env('BLOODHOUND_BLOCK_DC_IPS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Completion Cooldown
    |--------------------------------------------------------------------------
    |
    | How long after a user completes a torrent before another `completed`
    | announce from them counts as a genuinely new completion.
    |
    | `event=completed` is client-supplied and nothing validates it, and peer_id
    | is regenerated per client session — so one download reports completion
    | repeatedly whenever a client restarts or the user runs a second machine.
    | Counting each one inflates the total arbitrarily.
    |
    | Nothing in an announce distinguishes "the same download announcing again"
    | from "they deleted it and fetched it again months later", so time does.
    | A day is comfortably longer than client-restart churn and comfortably
    | shorter than a real return to a torrent.
    |
    */

    'completion_cooldown' => env('BLOODHOUND_COMPLETION_COOLDOWN', 86400),

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
    | It cannot be turned off. Every credit is written here and nowhere else,
    | so a tracker without it would credit nobody anything. (The `enabled` key
    | was removed in 8.0.0; a config still setting it false is refused.)
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
    | Pruning carries forward what it deletes: each row carries its own credit
    | (bytes and seconds), and what the pruned rows credited moves into
    | ledger_carry in the same transaction, so reconcile and rebuild-totals
    | stay exact after a prune. Rows the aggregator has not consumed yet are
    | never pruned, whatever their age. So the table stops at your retention
    | window.
    |
    */

    'announce_log' => [
        'connection' => env('BLOODHOUND_ANNOUNCE_LOG_CONNECTION'),
        'retention_days' => env('BLOODHOUND_ANNOUNCE_LOG_RETENTION_DAYS'),
    ],
];
