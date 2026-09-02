# Changelog

All notable changes to `marque/guise` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

## [Unreleased]

> Enforces per-torrent restrictions on detail pages and downloads, and shows swarm counts.

### Added

- Seeder and leecher columns in the torrent listing, and a "show dead torrents"
  toggle (visible only when `trove.hide_dead_torrents` is on).

### Fixed

- **The torrent detail page and `.torrent` download performed no authorization
  at all.** Both now check `view` on the torrent, so a restricted torrent is
  not reachable by direct URL. The download check matters most: the `.torrent`
  carries the user's announce key.

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
