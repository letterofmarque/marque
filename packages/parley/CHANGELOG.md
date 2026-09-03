# Changelog

All notable changes to `marque/parley` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md).

## [2.1.1] — 2026-09-03

> Widens the `marque/trove` constraint to allow trove 4.x. No functional change.

### Changed

- `marque/trove` constraint widened to `^3.0|^4.0`. Trove 4.0 changes
  `TorrentServiceInterface` signatures and removes a column, neither of which
  parley touches — but Composer would otherwise refuse to install parley
  alongside the rest of the suite. Nothing in this package behaves differently.

## [2.1.0] — 2026-09-01

> Adds off-by-default rate limiting for posts and replies; turn it on before any public deployment.

### Added

- Rate limiting for post/reply creation, off by default
  (`config('parley.rate_limiting.enabled')`, `PARLEY_RATE_LIMITING`). Enforced at the
  service layer (`PostService`) via Laravel's `RateLimiter`, keyed per-user. Closes
  job #10543 — **should be turned on before any public deployment**. See the
  package README's "Rate limiting" section.

## [2.0.0] — 2026-08-20

> Depends on `marque/ise` instead of the renamed `marque/id`.

### Changed

- **Breaking:** now depends on `marque/ise` instead of `marque/id`, following the rest
  of the frontend-facing packages. See [Marque 4.0](../../docs/releases/4.0.md).

## [1.0.0] — 2026-08-20

> First release — polymorphic threaded discussion for torrent comments and forum-lite.

Initial release. Polymorphic threaded discussion — torrent comments and an optional
lightweight forum from one model, rendered through `marque/squidink`.
