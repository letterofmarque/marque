# Marque

A modular BitTorrent tracker platform for Laravel.

Built by [Letter Of Marque Software](https://lom.software).

## Packages

| Package | Description |
|---------|-------------|
| [marque/trove](packages/trove) | Core models, services, contracts, and policies |
| [marque/bloodhound](packages/bloodhound) | Private BitTorrent tracker (announce/scrape) |
| [marque/cennad](packages/cennad) | REST API controllers and resources |
| [marque/guise](packages/guise) | Livewire web frontend (authenticated) |
| [marque/threepio](packages/threepio) | BitTorrent protocol primitives |
| [marque/hound](packages/hound) | Public BitTorrent tracker (no auth) |
| [marque/id](packages/id) | App layout shell (navigation, theming) |
| [marque/disguise](packages/disguise) | Public web frontend (browse without login) |
| [marque/usarrs](packages/usarrs) | Auth, user profiles, invites, admin |

## Requirements

- PHP 8.4+
- Laravel 13+
- Redis (for tracker peer storage)

## Installation

Install the packages you need:

```bash
# Core (required)
composer require marque/trove

# Web frontend
composer require marque/guise

# REST API
composer require marque/cennad

# BitTorrent tracker
composer require marque/bloodhound
```

## Features

### Trove (Core)
- Role-based access control (user, uploader, moderator, admin)
- Torrent model with file parsing and info_hash extraction
- User ratio tracking (uploaded, downloaded, seedtime)
- Configurable policies for torrent management

### Bloodhound (Tracker)
- Redis-backed peer storage for high performance
- Version-based client whitelist/blacklist
- Anti-cheat detection (speed limits, swarm consistency, connection limits)
- Ratio tracking modes: full, off, seedtime (ratioless)
- Support for compact and dictionary peer formats

### Guise (Web UI)
- Livewire components for torrent browsing, viewing, uploading, editing
- Configurable layouts
- Dependency-free Blade UI components (from marque/id), styled with Tailwind CSS

### Cennad (API)
- RESTful torrent endpoints
- Token-based authentication (Laravel Passport)
- Configurable routes and middleware

## Configuration

Publish the config files:

```bash
php artisan vendor:publish --tag=trove-config
php artisan vendor:publish --tag=bloodhound-config
php artisan vendor:publish --tag=guise-config
php artisan vendor:publish --tag=cennad-config
```

## Releasing

Packages are versioned independently. To release a package:

```bash
git tag <package>/v<version>
git push origin <package>/v<version>
```

For example:

```bash
git tag cennad/v2.0.1
git push origin cennad/v2.0.1
```

The split workflow parses the tag, splits only that package, and pushes the version tag to its sub-repo. Packagist picks it up automatically.

## Development

This is a monorepo. Each package in `packages/` is developed here and published to Packagist.

### Local Development

Add the path repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../marque/packages/*",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then require the packages with `@dev` stability:

```bash
composer require marque/trove:@dev marque/bloodhound:@dev
```

## License

MIT License - [Letter Of Marque Software](https://lom.software)
