# Changelog

All notable changes to `marque/guise` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[docs/upgrading.md](../../docs/upgrading.md) for the story up to this point.

## [5.1.0] — 2026-10-06

> Downloads are built for the member (their announce URL, a link back to the torrent) and uploads meet the installed tracker's rules before anything is stored.

### Changed

- **Downloads go through trove's `TorrentFileService`**: the stored info dictionary,
  untouched, with `announce` set to the installed tracker's URL for the member (their own key
  on bloodhound) and `comment` linking the torrent's page. A member with no announce key yet
  gets a 403 that says why. With no tracker installed, the stored file is served as before
  (#10947).
- **Uploads check the tracker's rules first.** A refusal (the private-flag rule, a v2-only
  torrent, or a file that isn't a torrent, which used to be a server error) shows on the file
  field, and nothing is stored. Warnings are passed on with the success message.
- Requires `marque/trove` ^4.5 (trove 3 is no longer accepted).

## [5.0.0] — 2026-09-11

> Requires `marque/deck` in place of `marque/ise`, and registers its own navigation entry instead of being detected by the shell.

### Changed

- **BREAKING: requires `marque/deck` `^2.0` instead of `marque/ise` `^1.0`.** The
  shell package was renamed; see the
  [upgrade guide](../../docs/upgrade-guide-ise-to-deck.md). This is a major
  because it changes the install set, not because guise's own API moved — no
  guise class, route or component changed.

- **guise registers its own nav entry.** The shell used to detect guise and add a
  Torrents link itself. guise now declares it against trove's `NavRegistry`, gated
  on an authenticated user — its listing sits behind auth, so a guest has nowhere
  to go.

- View references updated from `ise::` to `deck::`.

## [4.2.0] — 2026-09-04

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

## [4.1.0] — 2026-09-03

> Enforces per-torrent restrictions on detail pages and downloads, and shows swarm counts.

### Added

- Seeder and leecher columns in the torrent listing, and a "show dead torrents"
  toggle (visible only when `trove.hide_dead_torrents` is on).

### Fixed

- **The torrent detail page and `.torrent` download performed no authorization
  at all.** Both now check `view` on the torrent, so a restricted torrent is
  not reachable by direct URL. The download check matters most: the `.torrent`
  carries the user's announce key.

### Changed

- `marque/trove` constraint widened to `^3.0|^4.0` to allow trove 4.x.


## [4.0.0] — 2026-08-20

> Depends on `marque/ise` instead of the renamed `marque/id`.

### Changed

- **Breaking:** now depends on `marque/ise` instead of `marque/id`. See
  [Marque 4.0](../../docs/releases/4.0.md).

## [3.0.0] — 2026-08-13

> Raises the floor to PHP 8.4 and Laravel 13.

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
