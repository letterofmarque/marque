# Changelog

All notable changes to `marque/squidink` are documented here.

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

## [1.0.0] — 2026-08-15

> First release — Markdown and BBCode in, HTML and plain text out, through one shared document model.

Initial release. Markdown and BBCode in, HTML and plain text out, through one shared
document model. See the [package README](README.md) for what it does.
