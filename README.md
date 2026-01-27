# Marque

A modular BitTorrent tracker platform for Laravel.

Built by [Letter Of Marque Software](https://lom.software).

## Packages

| Package | Description |
|---------|-------------|
| [marque/trove](packages/trove) | Core models, services, contracts, and policies |
| [marque/bloodhound](packages/bloodhound) | BitTorrent tracker (announce/scrape) |
| [marque/cennad](packages/cennad) | REST API controllers and resources |
| [marque/guise](packages/guise) | Livewire web frontend components |

## Requirements

- PHP 8.2+
- Laravel 12+
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
- Flux UI compatible

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
