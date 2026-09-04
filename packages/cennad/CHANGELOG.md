# Changelog

All notable changes to `marque/cennad` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

## [4.1.0] — 2026-09-04

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

## [4.0.0] — 2026-09-03

> API read endpoints now require authentication by default; a public tracker opts out explicitly.

### Security

- **The API's read endpoints (`index`, `show`) now require authentication by default.**
  They were previously open to unauthenticated requests, while this package's own README
  stated that "all endpoints require authentication" — the documentation described the
  intended behaviour and the code shipped the opposite. Cennad cannot tell whether it is
  serving a private tracker (bloodhound/guise) or a public one (hound/disguise), so it now
  defaults to the private assumption.

  Installations that relied on the open default — including any private tracker that
  installed Cennad alongside Guise and assumed Guise's auth gate covered the API — were
  exposing their full torrent catalogue to anyone who could reach the endpoint.

### Added

- `seeders` and `leechers` on the torrent resource, and an `include_dead` query
  parameter on the index.

### Changed

- `show` now authorizes `view` on the torrent, and the index is filtered by the
  requesting user, so a torrent restricted by trove's `min_role` is not
  readable over the API by someone below that role.
- **Breaking:** `public_middleware` and `protected_middleware` are renamed to
  `read_middleware` and `write_middleware`. The old key names are still honoured and
  **take precedence** over the new ones, because Laravel merges the package defaults into
  every published config — so the presence of `read_middleware` proves nothing, while an
  old key can only be there because someone set it deliberately. Both old keys raise an
  `E_USER_DEPRECATED` notice and are removed in 5.0.

  **This means an untouched 3.x published config keeps its open reads.** If you published
  `config/cennad.php` on 3.x and never edited it, upgrading alone does not close your API —
  the deprecation notice tells you so. Rename the key to take the new default.
- **Breaking:** `read_middleware` defaults to `['api', 'auth:api']` (was `['api']`).
  `write_middleware` is unchanged at `['api', 'auth:api']`.
- `marque/trove` constraint widened to `^3.0|^4.0` to allow trove 4.x.

### Upgrading

**If you have never published `config/cennad.php`:** nothing to do. Reads now require
authentication.

**If you published it on 3.x:** it still contains `public_middleware`, which still wins.
Your reads stay as they were. Rename the keys to adopt the new defaults:
`public_middleware` → `read_middleware`, `protected_middleware` → `write_middleware`.

**Running a public tracker** (hound/disguise) and want the catalogue readable by guests?
Publish the config and drop the guard from reads only:

```php
'read_middleware' => ['api'],
```

Leave `write_middleware` alone when you do — uploads, edits and deletes stay governed by
Trove's `TorrentPolicy` on top of whatever middleware you set.

## [3.0.0] — 2026-08-13

> Raises the floor to PHP 8.4 and Laravel 13.

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
