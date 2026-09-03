# Changelog

All notable changes to `marque/trove` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

## [4.0.0] — 2026-09-03

> Adds per-torrent access control and swarm counts, and filters both in the listing.

### Added

- **`min_role` on torrents** — restrict a torrent to a minimum role. Null (the
  default) means everyone, so nothing is hidden by upgrading. Because trove's
  `Role` is ranked, restricting to `uploader` admits moderators and admins too.
  - `TorrentPolicy::view()` / `viewAny()` gate detail pages and `.torrent`
    downloads. Downloads carry the announce key, so they are gated exactly as
    tightly as viewing, never less.
  - `Torrent::scopeVisibleTo()` filters listings. This is the load-bearing
    half: a policy guards one record and would leave restricted torrents in
    every index, API collection and count.
  - The owner is deliberately not exempt — an uploader demoted below a
    torrent's `min_role` loses access to their own upload.
- **`seeders` / `leechers` on torrents** — a queryable projection of live peer
  state, which lives in Redis and cannot be filtered or sorted on in SQL.
  Maintained on the announce path by both bloodhound and hound.
- `trove.hide_dead_torrents` (default **false**) hides seederless torrents from
  listings, with `includeDead` to override per query. Off by default because a
  torrent has no seeders until its first announce, so filtering by default
  would hide fresh uploads and empty the catalogue of any install that has just
  upgraded.
- `ViewerScope` — makes "no viewer specified" a distinct state from "explicitly
  a guest", so an omitted argument resolves to the authenticated user rather
  than silently reading as an unauthenticated one.
- `TorrentFactory` states: `seeded()`, `dead()`, `restrictedTo()`.

### Removed

- **`visible`**, added days earlier in 3.x. It was only ever written in one
  place — set true when a seeder announced — and nothing ever set it back to
  false. Every torrent started true and could only go true, so the guard
  reading it was unreachable and the column carried no information. `seeders`
  replaces it with a value that has an invalidation path.

### Changed

- `TorrentServiceInterface::list()` and `find()` take an optional `ViewerScope`;
  `list()` also takes `includeDead`. Existing calls keep working and now filter
  by the authenticated user.

## [3.0.0] — 2026-08-13

> Raises the floor to PHP 8.4 and Laravel 13.

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
