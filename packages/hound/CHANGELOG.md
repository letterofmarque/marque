# Changelog

All notable changes to `marque/hound` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[docs/upgrading.md](../../docs/upgrading.md) for the story up to this point.

## [4.0.0] — 2026-10-06

> Downloads carry hound's open announce URL, and uploads must be public torrents by default.

### Changed (breaking)

- **Private torrents are refused by default.** Hound binds trove's new
  `TorrentFilePolicyInterface` with `uploads.private_flag` = `disallow`. A torrent carrying
  the flag is refused, and the uploader is told to recreate it with "private" unticked. The
  flag stops clients using DHT and peer exchange, which a public swarm relies on (#10947).
- Requires `marque/trove` ^4.5 (trove 3 is no longer accepted).

### Added

- **Downloads carry hound's own announce URL**, for guests and members alike, plus a
  `comment` linking the torrent's page, around the untouched info dictionary. The
  uploader's `announce-list` and other top-level keys are dropped (#10947).
- **`uploads.private_flag`** config (`HOUND_PRIVATE_FLAG`): `disallow`, `require`,
  `warn_if_private`, `warn_if_public` or `allow`. An unrecognised value is treated as
  `disallow`.

### Upgrading

- **To keep accepting private torrents**, set `HOUND_PRIVATE_FLAG=allow` (or
  `warn_if_private`) before upgrading.

## [3.3.1] — 2026-10-05

> Anonymous peers no longer pile into one ever-growing Redis set.

### Fixed

- **Every peer hound ever saw went into one Redis set that never shrank.** Hound passed
  `userId: 0` to threepio, which treats 0 as a real user id, so a `user:0:peers` set
  gathered every anonymous peer. Hound now passes `null`, and keeps no per-user state
  (#10804). After upgrading, delete the old set to free what's there. Its key is Laravel's
  `REDIS_PREFIX` (default `<app-name>-database-`) followed by threepio's
  `THREEPIO_REDIS_PREFIX` (default `marque:`) and `user:0:peers`, in your Redis
  connection's database. Pass the same `-h`/`-p`/`-a`/`-n` to both calls:
  `redis-cli --scan --pattern '*marque:user:0:peers' | xargs -r redis-cli del`. Take threepio 3.2.1 too: it fixes per-IP counts
  that could lock a busy IP out under `ip_limiting`.

## [3.3.0] — 2026-10-03

> Swarm counts fall back as peers leave (a stopped announce removes the peer, an hourly sweep clears expired ones), and announce and scrape paths are configurable for migrating public trackers.

### Added

- **`hound.routes` config** — `announce_path` and `scrape_path`, defaulting to the
  previous hardcoded `announce` and `scrape`. A public tracker migrating onto Marque
  cannot change the URL its circulating .torrent files announce to, and those files
  cannot be reissued.

  Paths only: hound is keyless by design, so none of bloodhound's key options apply
  here. Route names stay `tracker.announce` and `tracker.scrape` regardless.
- **`hound:sync-swarm-counts`**, scheduled hourly. It sweeps peers that expired without
  a `stopped` announce and writes the settled seeder/leecher counts back to each torrent.

### Changed

- **Requires `marque/threepio` ^3.1** (was ^3.0). The sweep relies on threepio 3.1's
  non-recursive peer removal; on threepio 3.0.0 it would loop on the first expired peer.
  threepio 3.1.0 has been out since 2026-09-04, and `composer update` picks it up.

### Fixed

- **Swarm counts only ever went up.** A `stopped` announce never removed the peer, and
  nothing swept peers that expired without one, so every peer that ever announced stayed
  counted. `stopped` now removes the peer, and the new command handles the rest (#10800).

## [3.2.0] — 2026-09-04

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

## [3.1.0] — 2026-09-03

> Records swarm counts on the announce path so a public catalogue can filter and sort on them.

### Changed

- Documented that `times_completed` on a public tracker counts **completed events seen**,
  not distinct completions. hound records no user, so there is nobody to dedupe against —
  a client restarting mid-download is indistinguishable from a second person finishing.
  bloodhound's equivalent is deduped per user; the two numbers are not comparable.
- The announce path writes `seeders`/`leechers` onto the torrent. Hound
  otherwise touches the database only on a completed event, so this is a
  deliberate addition to a hot path — without it a public catalogue cannot
  filter or sort on swarm state, because live peers are in Redis. The write is
  skipped when the counts have not changed.
- `marque/trove` constraint widened to `^3.0|^4.0` to allow trove 4.x.

## [3.0.0] — 2026-08-13

> Raises the floor to PHP 8.4 and Laravel 13.

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
