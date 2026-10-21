# Changelog

All notable changes to `marque/bloodhound` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[docs/upgrading.md](../../docs/upgrading.md) for the story up to this point.

## [8.1.0] — 2026-10-21

> What a real upgrade found: a stale published config is reported with the value actually in effect, imported members can be issued keys, a tracker with no scheduler says so, leechtime is reported, and a malformed announce key can get a reason instead of a 404.

### Added

- **`routes.malformed_key`** (`BLOODHOUND_MALFORMED_KEY`). With `failure`, a malformed key
  in the path gets a bencoded `failure reason` of `Invalid announce key`, not the app's
  HTML 404 (#11070). That covers a wrong length, extra segments or no key at all. A client
  shows the 404 as a garbled or unknown error. The route constraint still vets the key, and
  the fallback refuses it without a lookup. The default stays `not_found` (the documented
  404) through 8.x, and `failure` becomes the default in 9.0. With a published
  `config/bloodhound.php`, add the key to its `routes` block first; the env var is read
  only once it is there.
- **`bloodhound:issue-keys`** issues an announce key to every verified user without one (to
  every user, if the model doesn't verify addresses). Rows inserted without Eloquent, such
  as a bulk import or a migration from another tracker, never got one. It never touches an
  existing key. `--dry-run` reports the count.
- **A user verified by setting `email_verified_at` directly** now gets a key, as one verified
  through the `Verified` event already did. This covers an admin screen, a seeder or an
  import. An existing key is never rotated.
- **Announces report a ledger nobody is folding.** At most once every five minutes, an
  announce checks whether the oldest row not yet folded into totals is more than ten minutes
  old. If it is, it logs an error naming `schedule:run`. A tracker without the scheduler
  credits nobody, and a scheduled check can't report that. The check never fails an
  announce.
- **A published config still setting keys bloodhound no longer reads is reported**, once a
  day. That covers the protocol settings that moved to threepio in 2.0.0
  (`announce_interval`, `min_announce_interval`, `max_peers_per_announce`, `peer_expiry`,
  `redis.connection`, `redis.prefix`), `logging` (replaced by `announce_log` in 4.1.0) and
  `queue` (removed in 8.0.0). For a moved key the warning names the threepio key and env
  var, the value your config sets and the value in effect. twentyt's
  `BLOODHOUND_MIN_ANNOUNCE_INTERVAL=900` had been silently replaced by 300 for several
  majors. The README's "Moved or removed earlier" table lists them all.
- **`statsFor()` reports leechtime**, which trove 4.7's `TrackerStats` carries. Requires
  `marque/trove ^4.7`.

## [8.0.0] — 2026-10-20

> Every announce is credited when it arrives, bytes and seedtime alike, against a Redis that must persist and never evict; anti-cheat stops refusing legitimate clients, seedboxes get their own speed cap, and members can end a session their restarted client left behind.

### Changed (breaking)

- **The tracker's Redis must persist and never evict.** Every announce is credited against
  the peer's last counters in Redis, so Redis is a store here, not a cache: run it with
  `appendonly yes` and `maxmemory-policy noeviction`, on a database of its own. **In
  production, announces are refused until it does**; elsewhere a warning is logged.
  `php artisan bloodhound:check-redis` reads both settings from `INFO` and says what to
  set. `BLOODHOUND_REDIS_DURABILITY=attested` covers a provider that makes the data
  durable without AOF; the eviction policy is checked regardless.
- **MySQL, MariaDB, PostgreSQL, or SQLite 3.33 or later.** The aggregator's batched writes
  are spelled for those four and refuse any other driver; SQLite needs 3.33 for
  `UPDATE ... FROM`, and below it the aggregator fails with a SQL syntax error.
- **Requires `marque/threepio` ^4.0 and `marque/trove` ^4.6** (for `SeedboxesInterface`).
- **A speed over the cap forfeits the bytes instead of refusing the announce.** The
  direction over the cap is credited 0 and the baseline moves on, so those bytes never
  land later; the other direction and the time are still credited, and the row is flagged
  with the cap that applied. A refusal used to leave the baseline in place, so the next
  announce credited the same bytes and the cap delayed them by a minute.
- **`max_connections_per_ip` is removed; nothing limits how many torrents one address
  seeds.** It counted across torrents, so the members who seed thousands of torrents from
  one seedbox were the ones it refused. What a cap is for, many peer IDs polluting one
  torrent's swarm, is now `max_clients_per_torrent` (5): a member's live clients on one
  torrent, counted only for a new one. A published config still setting the old key gets
  a once-a-day notice.
- **Location limits replace `max_connections_per_torrent`.** A member may seed one torrent
  from `max_seeding_locations_per_torrent` (3) locations and leech it from
  `max_leeching_locations_per_torrent` (1). A location is an IP address, or an IPv6 /64.
  Only a new location is refused, so a client already in the swarm, or a second client at
  a location already in use, never is. The refusal tells the member they can end the other
  session (below).
- **No refusal touches Redis, and client-validation refusals are recorded too.** A
  client-validation or anti-cheat refusal writes a flagged ledger row with no credit and no
  baseline, and changes nothing in Redis, so the next accepted announce is measured from
  the last accepted one.

### Added

- **Seedtime and leechtime**, per member and per torrent, credited at announce time like
  bytes: the seconds since the peer's previous announce, capped at
  `threepio.peer_expiry`, attributed to the state the interval began in (the interval in
  which a download finishes is leech time). `seedtime` had always been written as 0.
  `announce_log` gains `seed_seconds`, `leech_seconds` and `prior_at`; the users table and
  `torrent_user` gain `leechtime`. A member's time is the sum over their clients. Counted
  in every `ratio_mode` (#10613).
- **Reconcile, rebuild and the audit cover time.** Every total is a sum of ledger rows
  plus carry; the audit checks each row's seconds against its `prior_at`.
- **`ledger_carry`**: credit whose ledger rows were pruned, or which the upgrade carried
  over, per member and torrent, on the same connection as `announce_log`.
- **Seedboxes.** Members register an address or a range (at most a /24 for IPv4, a /48 for
  IPv6); an admin approves it (`anti_cheat.seedbox.require_approval`, on by default), and
  its announces are then held to `anti_cheat.seedbox.max_upload_speed` /
  `max_download_speed` (10 Gbit/s) instead of the ordinary cap. Bloodhound implements
  trove's `SeedboxesInterface`, which usarrs renders; `bloodhound:seedbox list|approve|revoke`
  does the same from the console. Events: `SeedboxRegistered`, `SeedboxApproved`,
  `SeedboxRevoked`. The announce path matches against each member's cached approved
  networks, so it costs no query.
- **Members end their own sessions.** A client restarted under a new peer ID somewhere
  else is refused by the leeching limit until its old session expires; the member can end
  the old one instead. Bloodhound implements trove's `TrackerSessionsInterface`, which
  usarrs renders at `/profile/sessions`. Ending one removes the peer and any retained
  baseline and writes an `ended` ledger row that credits nothing (what the session moved
  since its last announce is lost) and closes the session like a `stopped`: the audit and
  recovery mode treat it as one.
- **Retained baselines** (`baseline_retention`, 7 days): the hourly sweep keeps an
  expired peer's record, so a client that comes back under the same peer ID without having
  sent `stopped` is credited what it moved in between.
- **Recovery mode.** If Redis loses its data, bloodhound notices and, for twice
  `peer_expiry`, reads a missing peer's baseline from the ledger.
  `bloodhound:check-redis --recover` opens a window by hand. Outside one, the announce
  path never reads the ledger.
- **`bloodhound:backfill-ledger`**: once, after upgrading, credits 7.x history the
  seedtime and leechtime between each session's consecutive announces and the bytes its
  stops reported, as carry. It rewrites no row.
- **`bloodhound:record-pruned-history`**: records credit an earlier prune deleted without
  carrying it, for installs that pruned in the past and have since turned retention off.
- **The aggregator's Redis check:** `bloodhound:aggregate-ledger` logs a failing
  durability check every minute, so a tracker nobody is announcing to still reports it.

### Fixed

- **A peer ID that isn't UTF-8 text was never credited, and failed the announce on MySQL,
  MariaDB and PostgreSQL** (BEP 3 allows any 20 bytes): threepio's record JSON-encoded to
  nothing, and the ledger column refused the bytes. It is stored hex-encoded when it has
  to be; the user agent is scrubbed (#11058).
- **Two announces from one peer at once were both credited**, the dual-stack case: a
  client announcing over IPv4 and IPv6 at the same moment. Each peer's announces are now
  handled one at a time under a short lock.
- **A dual-stack client's announce from its other address was refused by the minimum
  gap.** The gap applies to the same address only.
- **A seedbox lookup during a cache outage failed the announce**, and **one INFO call
  lost in transit refused every announce for five minutes.** The lookup falls back to the
  database, and a failing durability verdict is retried after 15 seconds.

- **About one torrent in twenty could never be announced, and some clients were refused.**
  Laravel's TrimStrings middleware strips NUL, space, tab, newline, carriage return and
  vertical tab from the ends of every query value, including the binary `info_hash` and
  `peer_id`. A torrent whose info hash started or ended with one of those bytes got
  "Invalid info_hash"; a client whose peer ID did got "Invalid peer_id length". Both are
  now read from the raw query string (#11057).
- **A scrape for several torrents answered for only the last.** PHP keeps only the last
  of a repeated `info_hash` key; every one is read now.

- **A member's record could be overwritten, or removed, by another member using the same
  peer ID**, and their next announce was credited nothing. A peer ID live in the swarm for
  another member is now refused.

- **Pruning no longer costs members their credit.** `bloodhound:prune-announce-log`
  deleted aggregated ledger rows and kept nothing. Reconciliation then reported every
  pruned member as drift, and the `rebuild-totals` it recommends replayed only the
  surviving rows, cutting real members' ratios. Every install with `retention_days` set was
  affected. A prune now carries each deleted row's bytes and seconds into `ledger_carry` in
  the same transaction, and reconciliation and rebuild read the ledger as its rows plus its
  carry (#11021).
- **The bytes a `stopped` announce reports are credited.** They were discarded.
- **A client restarted under the same peer ID is credited what it reports.** Its counters
  start from zero again, and a delta clamped to 0 lost everything it moved before its next
  announce.
- **A member at the connection limit was refused on every announce from every client**,
  because the count included the announcing peer. Limits now apply only to a new location
  or session.
- **`completed` and `stopped` were refused inside the minimum gap**, losing the completion
  or leaving a ghost peer, and every refusal reset the timer, so a client that announced
  early could be locked out. The gap now applies to regular announces only, is measured
  from the peer's last accepted announce, and a refusal changes nothing.
- **A resumed partial download was refused** by a data check that read the per-session
  `downloaded` as cumulative. The check is gone; more `left` than the torrent's size is
  reported as a `data_anomaly` and never refused.
- **The speed check was skipped when a baseline came from the ledger**, so those bytes
  were credited unchecked. Anti-cheat and the credit now read the peer once and judge the
  same baseline.
- **A baseline could come from a refused announce, or from another member** using the same
  peer ID. Neither is ever a baseline now, and the audit keys its chain by member, torrent
  and peer ID.
- **Two folds could count one batch twice.** The aggregator now locks the cursor row for
  each batch and advances it with a compare-and-swap; a rebuild takes the same lock, so a
  fold can't land between its reset and its write.
- **A row still committing could be stepped over and never counted.** A scheduled fold now
  stops at the highest id the previous run saw, so totals trail the ledger by a run or two.
- **The audit reported a chain break on every `stopped` and every refused announce.**
  Stops end a chain cleanly and refusals are not part of it; what remains is a real break,
  named: Redis lost a peer, or one interval was credited twice.
- **The per-IP count depended on how a client chose peer IDs**: once per torrent for some,
  once in total for others, and dropped on the first `stopped`. It now counts sessions.
- **A rebuild no longer creates all-zero per-torrent rows** for activity that moved
  nothing, which the aggregator never writes, so a rebuilt projection matches the one it
  replaces.
- **Docs:** peers leave Redis through the hourly sweep, not a TTL; only forfeits and
  anomalies fire `CheatDetected`, and refusals are logged rows; client-validation refusals
  are now logged as the docs said; the announce log is not optional.

### Removed

- **`announce_log.enabled`.** Every credit is written only to the ledger, so a tracker
  without it credits nobody anything. A config that still sets it to false is refused at
  boot, naming the key.
- **`queue.enabled`, `queue.connection`, `queue.queue`**, unread since the ledger replaced
  the stats queue (#10816).
- **`anti_cheat.max_connections_per_torrent`**, replaced by the location limits. Still set,
  it is ignored and a warning is logged once a day.
- **The data-sanity refusal**, and the `announce_time:*` Redis keys the minimum gap used.
- **The ledger read on every announce for a peer Redis didn't know.** Retained baselines
  and recovery mode replace it.

### Upgrading

The full steps are in the README under "Upgrading from 7.x to 8.0".

1. **Before upgrading, make Redis durable** (`appendonly yes`, `appendfsync everysec`,
   `maxmemory-policy noeviction`, a database of its own), or set
   `BLOODHOUND_REDIS_DURABILITY=attested`. In production 8.0 refuses announces until it is.
2. **If you have ever pruned, don't run `rebuild-totals` before upgrading.** On 7.x it
   would cut ratios.
3. **Update a published `config/bloodhound.php`**: delete `announce_log.enabled` and the
   `queue` block, replace `max_connections_per_torrent` with the two location limits, and
   delete `max_connections_per_ip`, and add the new keys (simplest: re-publish). Check the
   speed caps against your members.
4. **Upgrade bloodhound and threepio together and migrate.** The migrations add the new
   columns and tables, record stored seedtime and leechtime that no row explains as carry,
   and, with `retention_days` set, record earlier prunes' lost credit as carry. No row is
   rewritten and no rebuild is needed.
5. **After upgrading**, run `php artisan bloodhound:check-redis`, delete the old
   `ip:*:peers` and `announce_time:*` Redis keys, and, optionally,
   `php artisan bloodhound:backfill-ledger` to credit 7.x history its time and stopped
   bytes. If you pruned in the past and retention is now off, run
   `php artisan bloodhound:record-pruned-history`.

## [7.0.0] — 2026-10-06

> Downloads carry each member's own announce URL, and uploads must be private torrents by default.

### Changed (breaking)

- **Uploads must carry the `private` flag by default.** Bloodhound binds trove's new
  `TorrentFilePolicyInterface` with `uploads.private_flag` = `require`, so guise, disguise
  and cennad refuse a torrent without the flag. The uploader is told to recreate it with
  "private" ticked. Without the flag, clients share the swarm over DHT and peer exchange,
  outside the tracker. Marque never sets the flag for them, because it's inside the info
  dictionary and setting it would change the info_hash (#10947).

### Added

- **Each member's download carries their own announce URL**: the tracker's announce route
  with their key, in the path or query as `routes.*` configures it, plus a `comment`
  linking the torrent's page. Before this, downloads were the uploader's file unchanged,
  and members had to paste their tracker URL in by hand. A member with no key yet is refused
  with that reason (#10947).
- **`uploads.private_flag`** config (`BLOODHOUND_PRIVATE_FLAG`): `require`, `disallow`,
  `warn_if_public`, `warn_if_private` or `allow`. An unrecognised value is treated as
  `require`.
- Requires `marque/trove` ^4.5.

### Upgrading

- **To keep accepting non-private torrents**, set `BLOODHOUND_PRIVATE_FLAG=allow` (or
  `warn_if_public` to accept them with a warning) before upgrading.
- **Torrents already stored are not re-checked.** A non-private torrent uploaded before
  stays as it is, and its swarm can still leak over DHT. Re-upload it privately if that
  matters.

## [6.1.1] — 2026-10-03

> Ships the README corrections 6.1.0 should have contained; no code changes (comments only in the config).

### Fixed

- **6.1.0 on Packagist was missing its README corrections.** The split tool tagged the
  previous tree (an apostrophe in a commit message broke it). 6.1.0's code, including the
  key hold-back and the announce status check, did ship. 6.1.1 adds the docs:
  - timing, Redis and peer-response settings are threepio's (`redis.prefix` defaults to
    `marque:`)
  - the client lists are top-level `whitelist` / `blacklist`
  - the announce log is on by default
  - the announce flow writes to the ledger, not a queue
  - 9 whitelisted clients, four ledger commands
  - known-unbuilt and retired keys carry check-docs markers in the config (and the
    retired `queue.*` rows in the README)

## [6.1.0] — 2026-10-03

> Security: banned and disabled users can no longer announce, and an app that verifies email addresses issues announce keys on verification instead of at sign-up.

### Security

- **An announce key was issued to every new user, verified or not.** If the app's
  `User` implements `MustVerifyEmail`, `HasTrackerStats` now holds the key back from
  an unverified user and issues it when Laravel's `Verified` event fires. A user who
  already has a key keeps it, so nobody has to re-download their `.torrent` files.
  Not retroactive: unverified users who already hold a key keep it.
- **Banned and disabled users could keep announcing.** The announce check asked
  `property_exists()` about `enabled` and `status`, which is always false for an
  Eloquent attribute, so every user counted as enabled. It now reads the
  attributes. An `enabled` column set to false refuses, as do the statuses
  `banned`, `disabled` and `pending`, which are the same set usarrs refuses. Any
  other status value is left alone.

## [6.0.0] — 2026-09-25

> Announce keys move into a table bloodhound owns and other packages read tracker figures through a declared contract; the announce URL's shape also becomes configurable for migrating trackers.

### Added

- **`bloodhound.routes` config.** `announce_path`, `scrape_path`, `key_source`
  (`path` or `query`), `key_parameter` and `key_pattern`. Every default matches the
  previous hardcoded behaviour, so an existing install sees no change.

  This exists because a migrating tracker cannot change its announce URL: every .torrent
  already in circulation points at the old one and those files are on strangers' disks.
  The common case is a TBDev or Gazelle-derived tracker with `announce.php?passkey=`.

  It is deliberately *configurable routing*, not a legacy compatibility mode — there are
  no per-tracker special cases and there should never be any.

- `Marque\Bloodhound\Support\AnnounceRouting`, the single source of truth for the URL
  shape. The key format used to be asserted in **four** places — both route constraints
  and a `preg_match` in each controller, each spelling out `[0-9a-zA-Z]{32}`
  independently. Harmless while hardcoded and identical; guaranteed to drift once
  configurable. The router and the controllers now read the same value.

### Changed

- `AnnounceController::__invoke()` and `ScrapeController::__invoke()` take the announce
  key as an optional parameter and resolve it through `AnnounceRouting`, because in
  query mode the route declares no path parameter.

  **Behaviour with default config is unchanged**, including a malformed key in the path
  still being a 404 from the router. In *query* mode a malformed or missing key is a
  bencoded `failure reason` with HTTP 200 — the shape every other tracker error uses,
  since a 4xx reads to a client as an unreachable tracker rather than a message.


### Breaking

Upgrade guide: [bloodhound v6 / usarrs v8](../../docs/upgrade-guide-bloodhound-v6-usarrs-v8.md).

- **Announce keys live in `announce_keys`, not `users.announce_key`.** A migration creates
  the table and copies every existing key across (INSERT … SELECT, one statement). The old
  column is **left in place and never read or written again** — no drop, no rename; drop it
  yourself if you want it gone. Code that reads **`$user->announce_key` gets a stale or null
  value** and must switch to `app(TrackerStatsInterface::class)->announceKeyFor($user)`.
- **`HasTrackerStats` no longer has `generateAnnounceKey()` or `regenerateAnnounceKey()`.**
  Issue keys through `TrackerStatsInterface::regenerateAnnounceKey()`. The trait still
  issues a key to each new user, now into `announce_keys`.
- **`HasTrackerStats` no longer adds `announce_key`, `uploaded`, `downloaded` or `seedtime`
  to your User model's `$fillable`.** See Security. If your own code mass-assigned any of
  them, it now silently drops them — bloodhound never did.
- **Requires `marque/trove` `^4.3`**, for the contract.
- **Conflicts with `marque/usarrs` below 8.0.** usarrs 7 reads `users.announce_key` and calls the
  trait's removed key methods, so beside bloodhound 6 it would show a stale key and 404 on
  regenerate. Composer now refuses the pair instead of letting it half-work.

### Added

- **`TrackerStatsService`**, bound to trove's `TrackerStatsInterface`. Registered
  unconditionally: the figures exist in every `ratio_mode`, and the binding *is* the "a
  tracker is installed" signal. Reads go to storage by the user's key, never to attributes
  on the instance passed in, so a model loaded at the start of a request cannot report
  figures the ledger has moved past.
- **`Marque\Bloodhound\Models\AnnounceKey`** — nothing mass-assignable.
- **`MassAssignmentTest`**, as CONTRIBUTING.md asks of every package with models.

### Fixed

- **Announce keys longer than 32 characters can now be stored.** `key_pattern` is
  configurable and documented as widenable, but `users.announce_key` is `varchar(32)`, so
  on MySQL, MariaDB and PostgreSQL a longer key could not be saved at all. SQLite ignores
  varchar length, which is how it went unseen. `announce_keys.key` is 255 wide.
- **`HasTrackerStats` broke mass assignment on a `$guarded = []` User model.** Once
  `$fillable` is non-empty Laravel accepts only what it lists, so the trait's
  `mergeFillable` silently dropped `name`, `email` and every other column from `create()`.

### Security

- **A tracker credential and a user's ratio were mass-assignable on your User model.**
  `HasTrackerStats` added them to `$fillable`, so an ordinary
  `User::create($request->all())` let a user choose their own announce key and upload
  figure. Nothing in Marque exploited it, but the guarantee was yours, not ours. bloodhound
  writes these through the query builder and its own service, neither of which needs them
  fillable.

### Deprecated

- **`users.announce_key`.** Never read or written by bloodhound from this version.

## [5.1.0] — 2026-09-04

> Lowers the PHP floor to 8.3, matching Laravel 13's own requirement.

### Changed

- **`php` constraint widened from `^8.4` to `^8.3`.** Nothing in this package
  ever required 8.4 — no property hooks, no asymmetric visibility, none of the
  8.4 array or `mb_*` functions — and Laravel 13 itself only requires `^8.3`.
  The old floor turned away working Laravel 13 apps for no technical reason.

  Lowering a floor never breaks an existing install: if you are on 8.4 you stay
  on 8.4 and nothing changes.

- Dev-only: the test suite moved from Pest 5 to Pest 4, because Pest 5 requires
  PHP 8.4 and so made the floor untestable. The suite uses only `it`/`test`/
  `expect`/`describe`/`beforeEach`, which are identical across both. No effect
  on consumers — `require-dev` is not installed downstream.

## [5.0.0] — 2026-09-03

> Makes the announce log the durable source of truth for ratio, adds the reconciliation, rebuild and audit that make a wrong number detectable, and keeps swarm counts honest with an hourly reaper.

### Security

- **Ratio could be silently wrong, permanently, with nothing able to detect it.** Byte
  deltas were computed against a baseline held only in Redis and then carried as a queued
  job payload. A lost job was a lost credit, unrecoverable because Redis had already
  advanced the baseline — and since bloodhound requires Redis, most deployments point the
  queue at the same instance, so one restart could take both halves. `users.uploaded` was
  a pure accumulator with no durable record behind it, so a wrong number stayed wrong and
  nothing anywhere could tell. Ratio is what gets people banned.

### Added

- **The ledger.** `announce_log` is now the source of truth, written **synchronously** on
  the announce path (0.5ms) rather than dispatched. New `prior_up`/`prior_down` columns
  record the baseline each delta was computed against, so every credit carries its own
  arithmetic proof.
- **Baseline recovery.** A Redis miss now recovers the peer's last cumulative counters
  from the ledger instead of crediting zero. A total Redis loss costs latency, not bytes.
- **`torrent_user`** — per-user-per-torrent bytes, seedtime and completions. This
  intersection was computed on every announce and thrown away, which made hit-and-run
  enforcement impossible to build.
- **`bloodhound:aggregate-ledger`** (every minute) folds ledger rows into totals via a
  watermark cursor advanced in the same transaction as the write.
- **`bloodhound:reconcile-ledger`** (daily) compares totals against the ledger and reports
  drift loudly. **`bloodhound:audit-ledger`** checks the ledger's own coherence, including
  the per-peer baseline chain — a break is what a past Redis outage looks like.
  **`bloodhound:rebuild-totals`** recomputes everything from the ledger.
- `completion_cooldown` config (default 1 day).
- **`bloodhound:sync-swarm-counts`**, scheduled hourly — reconciles each
  torrent's `seeders`/`leechers` against live Redis peer state.

  This is what makes the counts trustworthy. The announce path keeps them fresh
  while peers are announcing, but a peer that simply vanishes — client killed,
  machine off — never sends `stopped`; its Redis entry expires silently and
  nothing announces afterwards to correct the row. Without a sweep the counts
  rot exactly the way the `visible` flag did: a write path with no invalidation
  path. Hourly rather than daily because until it runs, the catalogue advertises
  swarms that are gone.

### Changed

- **Breaking:** `announce_log.enabled` now defaults to **true**. A source of truth cannot
  be opt-in: an install without it has no way to know its ratios are wrong.
- **Breaking:** `snatches` is absorbed into `torrent_user`. It was read by nothing, and
  its `updateOrCreate` overwrote `completed_at` on a redownload — a January completion
  re-completed in July left one row dated July, destroying the date a hit-and-run rule
  measures from. Existing rows are carried over.
- `times_completed` is deduped per download session. It was a blind increment on a
  client-supplied `event` parameter, and peer_id is regenerated per client session, so a
  restart or second machine inflated it — five completions from one user counted as three.
- Pruning now refuses to delete rows the aggregator has not consumed, whatever their age.
- The announce path writes `seeders`/`leechers` onto the torrent (skipping the
  write when unchanged, since counts are stable between most announces).
- Stopped writing `visible`, which trove has removed. The write was a no-op: it
  could only ever set the flag true, and nothing set it false.
- **Deprecated:** `queue.*` is no longer read and is removed in the next major.
- `marque/trove` constraint widened to `^3.0|^4.0` to allow trove 4.x.

### Upgrading

The migration carries each user's pre-ledger totals in as an `opening_balance` row and
folds it immediately, so reconciliation starts clean rather than reporting every user's
entire history as drift. That row asserts the old total was correct as of migration; it is
not evidence it ever was.

Per-torrent history starts empty — it was never recorded — so hit-and-run enforcement only
means anything for torrents grabbed after the upgrade.

## [4.1.0] — 2026-09-02

> Adds an opt-in announce log for investigating cheating reports and settling disputed ratios.

### Added

- **Announce log** — an opt-in, full-detail history of every announce, for
  operators who want to investigate cheating reports or settle disputed ratios
  after the fact. Off by default; nothing changes for existing installs unless
  you turn it on. See the [README](README.md#announce-log).
  - `announce_log` table, append-only, written via a queued `LogAnnounce` job
    so the announce path never blocks on it. No job is dispatched at all when
    the feature is disabled.
  - Records both the client's cumulative totals and the calculated per-announce
    deltas, plus the anti-cheat verdict and reason for that announce.
  - `announce_log.connection` puts the table on any connection from
    `config/database.php`, so a high-write-volume log can live on a separate
    database with no other change. Migrations follow the same config.
  - `announce_log.retention_days` with a scheduled
    `bloodhound:prune-announce-log` command. Defaults to `null` — keep
    everything — so with logging on and retention unset the table grows
    without bound. That is deliberate, and documented.
  - `AnnounceLogServiceInterface` / `AnnounceLogService` for querying:
    `forUser`, `forTorrent`, `forUserAndTorrent`, `flagged`, `byIp`.
  - Bloodhound only. `marque/hound` has no equivalent — public trackers record
    no user against an announce, so the feature has nothing to attach to.

### Removed

- The `logging` config block (`BLOODHOUND_LOGGING`, `BLOODHOUND_LOG_CHANNEL`).
  It was scaffolded but never read anywhere in the package — no behaviour
  changes for anyone. Removed rather than left alongside `announce_log` as a
  second, dead "should I turn on logging" knob.

### Fixed

- Dropped an unused variable assignment in `AnnounceService::handleStopped()`.

## [4.0.0] — 2026-08-26

> Renames the tracker `passkey` column to `announce_key`, avoiding a collision with Laravel's WebAuthn passkeys.

### Changed

- **Breaking:** the tracker `passkey` column, and everything referencing it
  (controllers, routes, the `HasTrackerStats` trait), is renamed to
  `announce_key`. Existing keys are preserved by migration — no user gets a
  new key. See [Marque 4.1](../../docs/releases/4.1.md) and the
  [full upgrade guide](../../docs/upgrade-guide-bloodhound-v4-usarrs-v5.md).

## [3.0.0] — 2026-08-13

> Raises the floor to PHP 8.4 and Laravel 13.

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
