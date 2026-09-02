# Marque Trove

Core models, services, and contracts for the [Marque](https://github.com/letterofmarque/marque) tracker platform.

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

- **Torrent model** - info_hash, metadata, file storage, bencode parsing
- **TorrentService** - CRUD, .torrent file upload/parsing, search
- **Role system** - User, Uploader, Moderator, Admin hierarchy
- **Tracker stats** - Announce key generation, upload/download/seedtime tracking per user
- **Authorization** - Policies for create, update, delete operations

## User Model Setup

Add the Trove traits and interface to your User model:

```php
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Concerns\HasTrackerStats;
use Marque\Trove\Contracts\UserInterface;

class User extends Authenticatable implements UserInterface
{
    use HasRoles, HasTrackerStats;
}
```

`HasRoles` gives you role checks:

```php
$user->isAdmin();
$user->isModerator();
$user->isUploader();
$user->hasRoleAtLeast(Role::Moderator);
```

`HasTrackerStats` gives you tracker integration:

```php
$user->announce_key;                // Auto-generated 32-char key
$user->getRatio();                 // Upload/download ratio
$user->getRatioForHumans();        // "1.25" or "Inf"
$user->getUploadedForHumans();     // "4.2 GB"
$user->meetsRatioRequirement(0.5); // Boolean
```

## Working with Torrents

```php
use Marque\Trove\Contracts\TorrentServiceInterface;

$service = app(TorrentServiceInterface::class);

// List with pagination and search
$torrents = $service->list(perPage: 25, search: 'ubuntu');

// Upload a .torrent file (extracts info_hash, size, file count automatically)
$torrent = $service->createFromUpload($file, $user, 'Ubuntu 24.04', 'Official ISO');

// Find by info hash
$torrent = $service->findByInfoHash('a1b2c3d4...');

// Update
$service->update($torrent, ['name' => 'New Name']);

// Delete (removes stored file too)
$service->delete($torrent);
```

## Configuration

Published to `config/trove.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `user_model` | `App\Models\User` | Your User model class |
| `storage_disk` | `local` | Filesystem disk for .torrent files |
| `ratio_mode` | `full` | Ratio enforcement: `full`, `off`, or `seedtime` |
| `min_ratio` | `0.5` | Minimum required ratio (when mode is `full`) |
| `min_seedtime` | `86400` | Minimum seedtime in seconds (when mode is `seedtime`) |
| `hide_dead_torrents` | `false` | Hide torrents with no seeders from listings |

## Migrations

Trove creates:

- `torrents` table (info_hash, name, description, size, file_count, torrent_file, user_id, min_role, seeders, leechers)
- Adds `role` column to users table
- Adds `announce_key`, `uploaded`, `downloaded`, `seedtime` columns to users table

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
  index, API collection and count. `TorrentService` applies it for you.

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

- PHP 8.4+
- Laravel 13+

## License

MIT
