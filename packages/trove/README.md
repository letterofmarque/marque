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
- **Tracker stats** - Passkey generation, upload/download/seedtime tracking per user
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
$user->passkey;                    // Auto-generated 32-char key
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

## Migrations

Trove creates:

- `torrents` table (info_hash, name, description, size, file_count, torrent_file, user_id)
- Adds `role` column to users table
- Adds `passkey`, `uploaded`, `downloaded`, `seedtime` columns to users table

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

- **Create** - Uploader role or above
- **Update** - Torrent owner, or Moderator+
- **Delete** - Moderator or above

## Requirements

- PHP 8.2+
- Laravel 12+

## License

MIT
