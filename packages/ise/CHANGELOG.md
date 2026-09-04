# Changelog

All notable changes to `marque/ise` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md).

## [1.1.0] — 2026-09-04

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

## [1.0.1] — 2026-09-01

> Fixes a stray pre-rename `id-navigation` tag that broke every page using the shipped layout.

### Fixed

- The shipped layout (`layouts/app.blade.php`) referenced the pre-rename
  `<livewire:id-navigation />` tag instead of `ise-navigation` — broke every page using
  the ise-based layout on a fresh install. Found via twentyt's cold-upgrade test
  (job #10602).

## [1.0.0] — 2026-08-20

> First release under this name — the app shell and shared Blade components, replacing `marque/id`.

Initial release under this name — `marque/ise` replaces `marque/id`, which is now
abandoned on Packagist and points here. Same package, same purpose (the shared app
layout shell), new name. See [Marque 4.0](../../docs/releases/4.0.md) for why, and
what to do if you're migrating from `marque/id`.

`marque/id`'s own history (v2.0.0 through v3.0.0) isn't repeated here — see `git log`
if you need it.
