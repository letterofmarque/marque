# Changelog

All notable changes to `marque/bloodhound` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

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
