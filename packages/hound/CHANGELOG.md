# Changelog

All notable changes to `marque/hound` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

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
