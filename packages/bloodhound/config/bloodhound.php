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
    | REQUIRES REDIS, AND A REDIS THAT KEEPS WHAT IT HOLDS. Peer storage is a
    | real Redis server, not Laravel's cache pointed at one — PeerService uses
    | sets, hashes and atomic counters, none of which the cache abstraction
    | offers. You need ext-redis or predis/predis installed; see
    | packages/threepio/README.md.
    |
    | Every announce is credited against the peer's last counters, held in
    | Redis, so Redis is a store here, not a cache: run it with appendonly yes
    | and maxmemory-policy noeviction, on a database of its own. See 'redis'
    | below for what happens when it isn't.
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
    | 'malformed_key'  How a malformed key in the PATH is answered: 'not_found'
    |                  (the router's 404, the 8.x default) or 'failure' (a
    |                  bencoded 'failure reason', HTTP 200). 'failure' becomes
    |                  the default in 9.0.
    |
    | On error surfaces: BitTorrent clients show a bencoded 'failure reason';
    | a 404, or the app's HTML 404 page, reads as an unreachable tracker or a
    | garbled error. With the key in the query, a malformed one always gets the
    | failure reason. With it in the path, the route constraint vets it and
    | 'malformed_key' decides what a request that fails it gets.
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
        'malformed_key' => env('BLOODHOUND_MALFORMED_KEY', 'not_found'),
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
    | Every mode records the same figures: bytes, seedtime and leechtime. The
    | mode decides what is enforced.
    |
    | 'full'     - a minimum ratio (the default). A member below min_ratio who
    |              has downloaded at least enforcement.grace_bytes goes on
    |              watch for enforcement.watch_days; still below at the end,
    |              new downloading is paused until they recover. Seeding never
    |              is. Judged hourly by bloodhound:enforce-ratio.
    | 'seedtime' - per-torrent seeding rules instead of a ratio. Not built yet,
    |              so this enforces nothing for now.
    | 'off'      - no minimum. Nothing is enforced.
    |
    */

    'ratio_mode' => env('BLOODHOUND_RATIO_MODE', 'full'),

    // The minimum ratio 'full' enforces
    'min_ratio' => env('BLOODHOUND_MIN_RATIO', 0.5),

    // The minimum seedtime, in seconds, 'seedtime' will enforce
    // check-docs: ignore — not yet enforced, read by nothing until the hit-and-run Job after #10732
    'min_seedtime' => env('BLOODHOUND_MIN_SEEDTIME', 86400), // 24 hours

    // How the minimum ratio is enforced, under 'full'.
    //
    // 'watch_days'  - how long a member below min_ratio has to recover
    //                 before new downloading is paused.
    // 'grace_bytes' - nothing is enforced until a member has downloaded this
    //                 much, or every new member's first download would put
    //                 them on watch. 5 GiB by default.
    // 'exempt_role' - this role and above are never watched or restricted
    //                 (trove roles: user, uploader, moderator, admin). An admin
    //                 can also exempt one member: bloodhound:standing exempt.
    'enforcement' => [
        'watch_days' => env('BLOODHOUND_RATIO_WATCH_DAYS', 14),
        'grace_bytes' => env('BLOODHOUND_RATIO_GRACE_BYTES', 5 * 1024 ** 3),
        'exempt_role' => env('BLOODHOUND_RATIO_EXEMPT_ROLE', 'moderator'),
    ],

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

    // Clients allowed when mode = 'whitelist'. Each entry: a peer_id_pattern
    // whose captures are the version digits, the version_format they make,
    // and min_version, max_version (null for none) and blocked_versions.
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
    | Redis
    |--------------------------------------------------------------------------
    |
    | Every announce is credited against the peer's last counters, kept in
    | Redis, so Redis must keep what it holds: appendonly yes (appendfsync
    | everysec) and maxmemory-policy noeviction, on a database of its own
    | (THREEPIO_REDIS_CONNECTION). In production, announces are refused
    | until it does; elsewhere a warning is logged. A passing check is cached
    | for five minutes and a failing one for 15 seconds, and
    | `php artisan bloodhound:check-redis` says what to change. If your provider makes the data durable another way
    | (replication, snapshots) and offers no AOF, set durability to
    | 'attested': that covers persistence, and the eviction policy is still
    | checked whenever Redis answers INFO.
    |
    | If Redis loses its data anyway, bloodhound notices and, for twice
    | threepio.peer_expiry, reads a missing peer's baseline from the ledger
    | (recovery mode; `bloodhound:check-redis --recover` opens it by hand).
    |
    */

    'redis' => [
        'durability' => env('BLOODHOUND_REDIS_DURABILITY', 'required'),
    ],

    // Seconds an expired peer's record is kept as its baseline after its last
    // announce (7 days), so a member whose client comes back under the same
    // peer ID without having sent `stopped` is credited what it moved in
    // between. The hourly sweep moves expired records aside; a stopped peer
    // is never kept.
    'baseline_retention' => env('BLOODHOUND_BASELINE_RETENTION', 7 * 86_400),

    /*
    |--------------------------------------------------------------------------
    | Anti-Cheat
    |--------------------------------------------------------------------------
    |
    | Refuses only what a legitimate client never does, and never refuses a
    | peer already in the swarm on a count: a bad port, a regular announce
    | inside the minimum gap, a new location past the member's limit, a new
    | client past the member's clients on the torrent. A refusal is a flagged ledger row that credits
    | nothing and changes nothing in Redis; it fires no event. A speed over
    | the cap is forfeited, not refused; it, a data anomaly and a sampled
    | swarm anomaly fire CheatDetected.
    |
    | Removed in 8.0.0: max_connections_per_torrent, replaced by the two
    | location limits below. If it is still set it is ignored, and a warning
    | is logged once a day.
    |
    */

    'anti_cheat' => [
        'enabled' => env('BLOODHOUND_ANTICHEAT', true),

        // The speed cap for ordinary peers, in bytes/second, per direction.
        // Bytes claimed over it in one interval are forfeited: not credited,
        // and the baseline moves on so they never land later. The other
        // direction and the time are still credited. Lower it to what your
        // members' connections actually do; seedboxes have their own cap
        // below. (100 MiB/s is about 840 Mbit/s.)
        'max_upload_speed' => env('BLOODHOUND_MAX_UPLOAD_SPEED', 100 * 1024 * 1024),
        'max_download_speed' => env('BLOODHOUND_MAX_DOWNLOAD_SPEED', 100 * 1024 * 1024),

        // The cap for announces from inside one of the member's approved
        // seedboxes. 1,250,000,000 bytes/second is 10 Gbit/s.
        // Members register seedboxes (an address, or a provider's range);
        // with require_approval an admin approves each one before its cap
        // applies, and without it registering approves it. min_prefix_v4/v6
        // are the widest range allowed: a /24 (IPv4) or a /48 (IPv6), so a
        // range cannot take in other customers of the same provider. Only
        // the speed cap changes for a seedbox; the location limits and the
        // client cap still apply.
        'seedbox' => [
            'max_upload_speed' => env('BLOODHOUND_SEEDBOX_MAX_UPLOAD_SPEED', 1_250_000_000),
            'max_download_speed' => env('BLOODHOUND_SEEDBOX_MAX_DOWNLOAD_SPEED', 1_250_000_000),
            'require_approval' => env('BLOODHOUND_SEEDBOX_REQUIRE_APPROVAL', true),
            'min_prefix_v4' => 24,
            'min_prefix_v6' => 48,
        ],

        // Minimum seconds between two regular announces from one peer at one
        // address (a hammer guard), measured from its last accepted announce;
        // a dual-stack client's announce from its other address is not
        // hammering. started,
        // completed and stopped are never refused for timing, and a refusal
        // moves nothing, so a client retrying early is accepted once the gap
        // has passed.
        'min_announce_gap' => env('BLOODHOUND_MIN_ANNOUNCE_GAP', 60),

        // Locations a member may seed and leech one torrent from at once. A
        // location is an IP address (an IPv6 /64). Only a NEW location is
        // refused: a client already in the swarm is never cut off by a count,
        // so a leecher that finishes becomes a seeder without being refused,
        // and a second client at a location already in use is not new.
        // Leeching from more than one place is how one account is shared.
        // These replace max_connections_per_torrent.
        'max_seeding_locations_per_torrent' => env('BLOODHOUND_MAX_SEEDING_LOCATIONS', 3),
        'max_leeching_locations_per_torrent' => env('BLOODHOUND_MAX_LEECHING_LOCATIONS', 1),

        // A member's live clients (peer IDs) on one torrent, counted only for
        // a new one. Many peer IDs on one torrent pollute its swarm; one
        // client per torrent across thousands of torrents is a seedbox doing
        // its job, and how many torrents a member seeds is never limited.
        'max_clients_per_torrent' => env('BLOODHOUND_MAX_CLIENTS_PER_TORRENT', 5),

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
    | regular-interval, completed, and stopped request, accepted or refused,
    | with the cumulative totals the client reported, the bytes and seconds
    | credited, and the baseline they were measured against. Each row carries
    | its own credit, and every total is a sum of rows plus ledger_carry.
    |
    | This table is the source of truth for ratio and seedtime. User and
    | per-torrent totals are projections summed from it, and a wrong number
    | can only be detected — let alone corrected — by comparing it against
    | this. Ratio is what gets people banned.
    |
    | It cannot be turned off. Every credit is written here and nowhere else,
    | so a tracker without it would credit nobody anything. (The `enabled` key
    | was removed in 8.0.0; a config still setting it false is refused at
    | boot. The `queue` keys were removed too; nothing read them.)
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
