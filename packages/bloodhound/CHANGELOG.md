# Changelog

All notable changes to `marque/bloodhound` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

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
