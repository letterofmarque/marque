# Marque Trove

Core models, services, and contracts for the [Marque](https://github.com/letterofmarque/marque) tracker platform.

## Starting from scratch?

Trove is the catalogue core — models, roles, policies. On its own it tracks nothing
and serves no pages. Pick the shape you're building:

```bash
# Private tracker — login required, ratio tracked
composer require marque/trove marque/bloodhound marque/guise marque/usarrs marque/cennad

# Public tracker — open announce, browse without an account
composer require marque/trove marque/hound marque/disguise

# Torrent catalogue, no tracker
composer require marque/trove marque/guise marque/usarrs
```

Each resolves its own supporting packages (`threepio` for the BitTorrent protocol,
`deck` for the UI shell).

## Installation

```bash
composer require marque/trove
```

Publish the config and run migrations:

```bash
php artisan vendor:publish --tag=trove-config
php artisan migrate
```

## What's Included

- **Torrent model** - info_hash, metadata, file storage
- **TorrentService** - CRUD, .torrent file upload/parsing, search
- **Role system** - User, Uploader, Moderator, Admin hierarchy
- **Tracker stats contract** - `TrackerStatsInterface` and the `TrackerStats` / `TorrentStats` value objects; a tracker package (bloodhound) implements it, issuing keys and tracking bytes
- **Authorization** - Policies for create, update, delete operations

## User Model Setup

Add the Trove traits and interface to your User model:

```php
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Contracts\UserInterface;

class User extends Authenticatable implements UserInterface
{
    use HasRoles;
}
```

`marque:install` does this for you — it shows the diff, backs the file up and
applies it on confirmation. The manual version is here for anyone wiring the
packages up by hand.

**On a private tracker, add `HasTrackerStats` as well.** It lives in
`marque/bloodhound`, not trove:

```php
use Marque\Bloodhound\Concerns\HasTrackerStats;

class User extends Authenticatable implements UserInterface
{
    use HasRoles, HasTrackerStats;
}
```

A public tracker does not install bloodhound, so adding that trait there fatals
on a missing class.

`HasRoles` gives you role checks:

```php
$user->isAdmin();
$user->isModerator();
$user->isUploader();
$user->hasRoleAtLeast(Role::Moderator);
```

`HasTrackerStats` issues each new user an announce key (on email verification, when the app verifies addresses) and gives the model a few
formatting helpers (`getRatioForHumans()`, `getUploadedForHumans()`, …).

**To read tracker figures or a user's announce key from your own code, ask the tracker**
rather than the User model. trove declares the contract; bloodhound implements it; nothing
binds it on an install without a tracker:

```php
use Marque\Trove\Contracts\TrackerStatsInterface;

$tracker = app()->bound(TrackerStatsInterface::class)
    ? app(TrackerStatsInterface::class)
    : null;                                   // no tracker installed

$stats = $tracker?->statsFor($user);         // TrackerStats: uploaded, downloaded, seedtime, ratio
$key = $tracker?->announceKeyFor($user);     // the key for the user's announce URL
$tracker?->regenerateAnnounceKey($user);     // issue a new one; the old stops working

$stats->ratio;                // float, unrounded — or null, meaning INFINITE (nothing downloaded)
$stats->hasInfiniteRatio();   // say it without relying on the null
```

`statsForTorrent($user, $torrent)` returns a `TorrentStats` for one torrent, including when
the user first and last completed it. Every figure is a raw integer (bytes, seconds) —
formatting is yours.

⚠️ **`$user->announce_key` is not the user's key** as of bloodhound 6. Keys live in
bloodhound's own `announce_keys` table; the old `users.announce_key` column is left in place
but never read or written. Use `announceKeyFor()`.

## Working with Torrents

```php
use Marque\Trove\Contracts\TorrentServiceInterface;

$service = app(TorrentServiceInterface::class);

// List with pagination and search
$torrents = $service->list(perPage: 25, search: 'ubuntu');

// Upload a .torrent file (extracts info_hash, size, file count automatically).
// The info_hash is sha1 of the info dictionary's original bytes, as clients compute it.
$torrent = $service->createFromUpload($file, $user, 'Ubuntu 24.04', 'Official ISO');

// Find by info hash. Unscoped: it ignores min_role. Meant for internal lookups, so check
// the policy before showing its result to a user.
$torrent = $service->findByInfoHash('a1b2c3d4...');

// Update
$service->update($torrent, ['name' => 'New Name']);

// Delete (removes stored file too)
$service->delete($torrent);
```

### Checking stored info hashes

Before trove 4.4, uploads were hashed from the decoded-and-re-encoded info dictionary. A
torrent whose info wasn't canonical bencode was stored under a hash no client announces,
and never saw a peer. To find any such torrents:

```bash
php artisan trove:check-info-hashes
```

It re-hashes each stored .torrent the way clients do, lists every torrent whose stored
`info_hash` differs (with both hashes), and exits non-zero if it finds any. A torrent whose
file is missing, isn't valid bencode or has no info dictionary (missing, or not a dictionary) is listed as a warning and
skipped, and doesn't affect the exit code. It changes
nothing. The correct hash may already belong to another row, and anything keyed on the old
one needs thought, so fixing them is your call.

## Dashboard panels

The user dashboard (`/dashboard`, rendered by `marque/usarrs`) shows whatever panels
packages have registered. It names no package and holds no list of its own, so a package
Marque has never heard of gets a panel exactly as a first-party one does — same card, same
heading, ordered among the others by the position it chose.

A panel is a Livewire component you own plus a declaration in trove's registry. Register
from your own service provider's `boot()`, with a dependency on `marque/trove` alone:

```php
use Livewire\Livewire;
use Marque\Trove\Registry\DashboardPanel;
use Marque\Trove\Registry\DashboardPanelRegistry;

public function boot(): void
{
    Livewire::component('acme-stats-panel', AcmeStatsPanel::class);

    $this->app->make(DashboardPanelRegistry::class)->register(new DashboardPanel(
        identifier: 'acme-stats',
        label: 'Acme Stats',
        component: 'acme-stats-panel',
        position: 35,
        // Per request, against the signed-in user. Omit it to show the panel to
        // everyone who reaches the dashboard.
        visible: fn (?object $user): bool => $user !== null && $user->widgets()->exists(),
    ));
}
```

`DashboardPanel::forRole('acme-mod', 'Moderation', 'acme-mod-panel', Role::Moderator)` is the
shorthand for a role gate, with the same semantics as `NavItem::forRole()` — a guest never
clears it.

**Nothing depends on the dashboard in order to contribute to it.** Registering a panel needs
trove and nothing else; if usarrs is not installed the declaration simply goes unread. Your
package boots and behaves identically either way.

Three separate things decide whether a panel appears, and they belong in different places:

| | Where | Example |
|---|---|---|
| Your package is not installed | nothing registers | — |
| The capability is absent | don't register, in `boot()` | usarrs registers its tracker panels only when a tracker has bound `TrackerStatsInterface` |
| This user has nothing to show | the `visible` closure | the invites panel hides for a user with none to send and none outstanding |

usarrs's own panels sit at positions 10 (tracker stats), 20 (announce key), 30 (account
security) and 40 (invites), so pick a position between them to interleave. Panels are
ordered by `position`, then label. Registering a duplicate `identifier` throws rather than
silently replacing the existing panel.

**This is public API.** `DashboardPanel` and `DashboardPanelRegistry` — their constructor
parameters and public methods — follow trove's semver from 4.3, alongside the nav and
admin-screen registries (see [VERSIONING.md](../../VERSIONING.md)). The dashboard page that
renders them, and usarrs's own panels on it, version with usarrs.

## Configuration

Published to `config/trove.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `user_model` | `App\Models\User` | Your User model class |
| `storage_disk` | `local` | Filesystem disk for .torrent files |
| `hide_dead_torrents` | `false` | Hide torrents with no seeders from listings |

Ratio enforcement isn't configured here. It belongs to the tracker, and bloodhound's
`ratio_mode` / `min_ratio` / `min_seedtime` keys are planned and not enforced yet
(#10732).

## Migrations

Trove creates:

- `torrents` table (info_hash, name, description, size, file_count, torrent_file, user_id,
  min_role, seeders, leechers, times_completed)
- Adds a `role` column to the users table

That is all trove adds to `users`. The tracker columns (`uploaded`, `downloaded`,
`seedtime`) come from bloodhound's migrations, and announce keys live in bloodhound's own
`announce_keys` table.

Publish migrations to customise them:

```bash
php artisan vendor:publish --tag=trove-migrations
```

## Roles

Four roles with a strict hierarchy:

| Role | Rank | Can Upload | Can Moderate |
|------|------|------------|--------------|
| User | 0 | No | No |
| Uploader | 1 | Yes | No |
| Moderator | 2 | Yes | Yes |
| Admin | 3 | Yes | Yes |

## Authorization

Trove registers a `TorrentPolicy`:

- **View** - Everyone, unless the torrent sets `min_role` (see below)
- **Create** - Uploader role or above
- **Update** - Torrent owner, or Moderator+
- **Delete** - Moderator or above

### Restricting a torrent to a role

Set `min_role` to hide a torrent from users below that role. Null — the default
— means everyone can see it.

```php
$torrent->update(['min_role' => Role::Uploader]);
```

Because roles are ranked, restricting to `Uploader` also admits moderators and
admins. The torrent's own uploader is **not** exempt: an uploader demoted below
the torrent's `min_role` loses access to it.

Enforcement happens in two places, and both matter:

- `TorrentPolicy::view()` covers detail pages and `.torrent` downloads. The
  download is gated exactly as tightly as viewing, because the `.torrent`
  carries the announce key.
- `Torrent::scopeVisibleTo($user)` covers listings. A policy guards a single
  record; without the scope, restricted torrents would still appear in every
  index, API collection and count. `TorrentService`'s `list()` and `find()` apply it for
  you; `findByInfoHash()` deliberately does not.

```php
// Everything the current user may see:
$torrents = $service->list();

// Explicitly as a guest:
$torrents = $service->list(viewer: ViewerScope::guest());
```

Passing no viewer resolves to the authenticated user. That is deliberate — an
omitted argument must not be mistaken for "an unauthenticated visitor", or a
caller that forgets it quietly gets the wrong list.

### Dead torrents

`seeders` and `leechers` are kept on the torrent by the tracker packages as a
queryable projection of live peer state (which lives in Redis, where SQL cannot
filter or sort on it).

Set `hide_dead_torrents` to `true` to drop seederless torrents from listings;
`$service->list(includeDead: true)` overrides it. It is off by default because a
torrent has no seeders until its first announce — enabling it on a catalogue
that has not announced yet hides everything in it.

## Requirements

- PHP 8.3+
- Laravel 13+

## License

MIT. See [LICENSE](LICENSE).
