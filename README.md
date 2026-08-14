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

## Versioning

Packages follow [Semantic Versioning](https://semver.org) and are versioned
independently — `marque/guise` at 3.4.0 alongside `marque/threepio` at 3.0.1 is normal.

Most people want `^3.0`, which is what `composer require` gives you by default: new
features and fixes automatically, never a breaking change. Minor releases are cut
frequently, so you should not need to track `dev-main` to get a finished feature.

See [VERSIONING.md](VERSIONING.md) for the full policy — what counts as patch, minor and
major, how the grey areas are decided, pre-releases, and the support window.

## Releasing

Packages are versioned independently. Use the release script:

```bash
bin/release cennad 3.0.1                  # one package
bin/release guise 3.1.0 usarrs 3.1.0      # several at once
bin/release --all 3.1.0                   # every package
bin/release --dry-run guise 3.1.0         # show the plan, change nothing
```

It runs the test suites first, sorts the release into dependency order, tags, pushes in
safe batches, waits for each split workflow, and verifies the versions reached Packagist.

To release by hand, tag as `<package>/v<version>` and push. The split workflow parses the
tag, splits only that package, and pushes the version tag to its sub-repo; Packagist
picks it up automatically. Two things to watch:

- **Push tags in batches of three or fewer.** GitHub suppresses workflow triggers when
  more than three tags arrive in a single push — the tags land, nothing splits, and
  Packagist is never notified. It fails silently.
- **Release in dependency order.** A package must be published before anything that
  requires it: `threepio` → `trove` and `id` → everything else.

If a split is missed, re-trigger it from an existing tag:

```bash
gh workflow run 'Split Monorepo' -f tag=guise/v3.1.0
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
