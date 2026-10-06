# Changelog

All notable changes to `marque/trove` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[docs/upgrading.md](../../docs/upgrading.md) for the story up to this point.

## [4.5.0] — 2026-10-06

> Lets the installed tracker decide what an uploaded .torrent must be and what goes into a download: `TorrentFilePolicyInterface`, a `PrivateFlag` rule, and `TorrentFileService`, which refuses an upload that breaks the rule and builds each member's download around the untouched info dictionary.

### Added

- **`TorrentFilePolicyInterface`**, which a tracker package binds: `privateFlag()` (a
  `PrivateFlag`: allow, warn_if_public, warn_if_private, require, disallow) and
  `announceUrlFor(?UserInterface)`. bloodhound and hound bind it (#10947).
- **`TorrentFileService`**:
  - `inspect($content)` returns an `UploadInspection` with refusals (not a torrent,
    v2-only, or the private-flag rule broken), each naming the fix, plus warnings (a
    hybrid torrent, or a warn_* rule).
  - `forDownload($torrent, $user, $commentUrl)` keeps only `info` and `encoding` from the
    stored file, byte for byte, and sets `announce` to the tracker's URL for that user and
    `comment` to the page given. `announce-list` and every other top-level key are dropped
    (cross-seeding is #10950). With no tracker bound, it returns the stored file as is.
  - Throws `NoAnnounceUrl` when the tracker has no URL for the user.
- **`TorrentRefused`**: `createFromUpload()` throws it, carrying the reasons, before
  storing a torrent the tracker refuses.

### Fixed

- **`trove:check-info-hashes` hashed an `info` that wasn't a dictionary** (a string or an
  integer) and reported it as a mismatch. It now warns and skips it, like a missing one.
  Uploads can't create such a file, since they reject a non-dictionary info, so only a row
  pointed at a hand-made file was affected. Found by the 4.4.0 read-through.

## [4.4.0] — 2026-10-06

> Uploads are hashed the way clients hash them, so a torrent whose info dictionary isn't canonical bencode gets the info_hash it's announced under, and `trove:check-info-hashes` finds the ones stored wrongly before.

### Fixed

- **A non-canonical torrent was stored under an info_hash no client announces.** Uploads
  were hashed as `sha1(Bencode::encode($decoded['info']))`. The round trip normalises the
  info dictionary: key order, a dictionary with keys "0", "1"… turning into a list. When
  the uploaded file wasn't already canonical, the stored hash differed from the one every
  client computes over the file's own bytes, so the torrent never saw a peer. The download
  serves the original file, which hid the problem. The info_hash is now sha1 of the info
  dictionary's original bytes (#10946).

### Added

- **`php artisan trove:check-info-hashes`** lists torrents stored under a hash their own
  .torrent doesn't have, with both hashes, and exits non-zero if there are any. A missing
  file, invalid bencode or a file with no info dictionary is a warning, and skipped. Report
  only: rewriting a stored hash is left to you.

### Changed (docs)

- The README no longer lists a `visible` column (a later migration drops it), credits
  trove with key issuing (bloodhound does that), or calls the model a bencode parser. It
  notes that `findByInfoHash()` ignores `min_role`. `TorrentServiceInterface`'s comment
  no longer says null means a guest: null means the current user, and guests are
  `ViewerScope::guest()`.

### Changed

- **Requires `marque/threepio` ^3.3**, for `Bencode::rawDictionary()`.

## [4.3.0] — 2026-09-25

> Declares the tracker stats contract, so packages ask the installed tracker for a user's figures and announce key instead of probing the User model.

### Added

- **`Marque\Trove\Contracts\TrackerStatsInterface`** — `statsFor()`, `statsForTorrent()`,
  `announceKeyFor()`, `regenerateAnnounceKey()`. trove declares it and implements nothing;
  a tracker (bloodhound) binds it. On an install with no tracker nothing is bound, so
  `app()->bound(TrackerStatsInterface::class)` is the capability check. Use it in place of
  `method_exists($user, 'getRatio')`, which only ever answered "is a trait applied".
- **`Marque\Trove\Support\TrackerStats`** and **`TorrentStats`**, the readonly values it
  returns. Raw integers (bytes, seconds) and a `ratio` computed from them, unrounded.
  **A null `ratio` means infinite** — nothing downloaded — and `hasInfiniteRatio()` says so
  explicitly. `TorrentStats` also carries `firstCompletedAt`, `lastCompletedAt` and
  `timesCompleted`.
- **`Marque\Trove\Registry\DashboardPanel`** and **`DashboardPanelRegistry`** — the user
  dashboard's equivalent of the nav and admin-screen registries: a package contributes a
  panel to a dashboard it does not own.

## [4.2.0] — 2026-09-11

> Adds the surface registries — packages can now contribute navigation entries and admin screens to a shell that knows nothing about them.

### Added

- **`Marque\Trove\Registry\NavRegistry`** and **`AdminScreenRegistry`**, plus the
  `NavItem` and `AdminScreen` entries they hold. A package registers from its own
  service provider and depends on trove alone — never on whatever renders the
  result — so it behaves identically whether or not a shell or panel is
  installed.

  This is what makes third-party navigation and admin screens possible. Before
  it, the shell held a hardcoded list of the packages it knew about.

  `AdminScreenRegistry` is enumerable **without** a user, because a panel builds
  its route table from it at boot. `NavRegistry` is evaluated per request against
  the current user, with an arbitrary visibility rule — "show Invites only if this
  user has any" is a query, not a rank. Same idea, different lifecycles, which is
  why they are two classes rather than one abstraction.

  Registering a duplicate identifier throws rather than silently replacing the
  existing entry.

  **This contract is public API from this release.** See
  [`docs/integration.md`](../../docs/integration.md) Pattern 5.

- trove gains **no new dependencies** for this — no view layer, no Livewire. The
  registries are plain PHP.

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
