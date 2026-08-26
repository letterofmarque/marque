# Changelog

All notable changes to `marque/usarrs` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

## [5.0.0] — 2026-08-26

### Changed

- **Breaking:** the profile page's `PasskeyManagement` component is renamed
  to `AnnounceKeyManagement`, along with its route, Livewire tag, and the
  `show_passkey`/`allow_passkey_regen` config keys (now
  `show_announce_key`/`allow_announce_key_regen`). See
  [Marque 4.1](../../docs/releases/4.1.md) and the
  [full upgrade guide](../../docs/upgrade-guide-bloodhound-v4-usarrs-v5.md).

## [4.0.0] — 2026-08-20

### Changed

- **Breaking:** now depends on `marque/ise` instead of `marque/id`. See
  [Marque 4.0](../../docs/releases/4.0.md).

## [3.0.0] — 2026-08-13

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
