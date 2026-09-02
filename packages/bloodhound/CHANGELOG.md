# Changelog

All notable changes to `marque/bloodhound` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

## [4.1.0] — 2026-09-02

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

### Changed

- **Breaking:** the tracker `passkey` column, and everything referencing it
  (controllers, routes, the `HasTrackerStats` trait), is renamed to
  `announce_key`. Existing keys are preserved by migration — no user gets a
  new key. See [Marque 4.1](../../docs/releases/4.1.md) and the
  [full upgrade guide](../../docs/upgrade-guide-bloodhound-v4-usarrs-v5.md).

## [3.0.0] — 2026-08-13

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
