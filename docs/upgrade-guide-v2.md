# Upgrading to Marque v2.0

This guide covers migrating from Marque v1.x to v2.0. The major change is the extraction of shared protocol code into `marque/threepio` and the introduction of five new packages.

## Before You Start

1. Back up your database
2. Note any custom overrides of Marque config, views, or migrations
3. Clear your application cache: `php artisan cache:clear`

## Step 1: Update Composer Dependencies

Update your `composer.json` to require v2.0:

```bash
composer require marque/trove:^2.0 marque/bloodhound:^2.0 marque/cennad:^2.0 marque/guise:^2.0
```

This will automatically pull in:
- `marque/threepio` (required by bloodhound)
- `marque/id` (required by guise)

## Step 2: Publish New Config Files

```bash
php artisan vendor:publish --tag=threepio-config
php artisan vendor:publish --tag=id-config
```

## Step 3: Migrate Bloodhound Config

The following keys have **moved from** `config/bloodhound.php` **to** `config/threepio.php`:

| Old location (bloodhound) | New location (threepio) |
|---|---|
| `announce_interval` | `announce_interval` |
| `min_announce_interval` | `min_announce_interval` |
| `max_peers_per_announce` | `max_peers_per_announce` |
| `peer_expiry` | `peer_expiry` |
| `redis` | `redis` |
| `peer_response_format` | `peer_response_format` |
| `blacklisted_ports` | `blacklisted_ports` |

Copy any custom values from your `config/bloodhound.php` to `config/threepio.php`, then remove the old keys from `config/bloodhound.php`.

## Step 4: Migrate Trove Config

The following keys have **moved from** `config/trove.php` **to** `config/bloodhound.php`:

| Old location (trove) | New location (bloodhound) |
|---|---|
| `ratio_mode` | `ratio_mode` |
| `min_ratio` | `min_ratio` |
| `min_seedtime` | `min_seedtime` |

Remove these keys from `config/trove.php`. The remaining keys (`storage_disk`, `user_model`) stay in trove.

## Step 5: Re-publish Bloodhound Migrations

The tracker stats migration has moved from trove to bloodhound. If you haven't modified it:

```bash
php artisan vendor:publish --tag=bloodhound-migrations --force
```

If you have custom modifications, compare and merge manually.

## Step 6: Update Redis Key Prefix

The Redis key prefix changed from `bloodhound:` to `marque:`.

**Option A** (recommended): Clear existing peer data and let it rebuild:
```bash
php artisan tinker
> Illuminate\Support\Facades\Redis::connection(config('threepio.redis.connection'))->flushdb()
```

**Option B**: Keep the old prefix by setting in `config/threepio.php`:
```php
'redis' => [
    'prefix' => 'bloodhound:',
],
```

## Step 7: Update Namespace References

If you reference any of these classes directly, update the imports:

| Old namespace | New namespace |
|---|---|
| `Marque\Bloodhound\Support\Bencode` | `Marque\Threepio\Support\Bencode` |
| `Marque\Bloodhound\Support\TrackerResponse` | `Marque\Threepio\Support\TrackerResponse` |
| `Marque\Bloodhound\Enums\AnnounceEvent` | `Marque\Threepio\Enums\AnnounceEvent` |
| `Marque\Bloodhound\Http\Middleware\BlockBrowsers` | `Marque\Threepio\Http\Middleware\BlockBrowsers` |
| `Marque\Bloodhound\Services\PeerService` | `Marque\Threepio\Services\PeerService` |
| `Marque\Trove\Concerns\HasTrackerStats` | `Marque\Bloodhound\Concerns\HasTrackerStats` |

## Step 8: Update Cennad Middleware Config

The `middleware` key in `config/cennad.php` has been split:

**Before (v1.x):**
```php
'middleware' => ['api', 'auth:api'],
```

**After (v2.0):**
```php
'public_middleware' => ['api'],
'protected_middleware' => ['api', 'auth:api'],
```

GET endpoints (index, show) now use `public_middleware` (no auth by default). POST/PUT/DELETE use `protected_middleware`.

A new `store` endpoint is also available: `POST /api/torrents`.

## Step 9: Update Guise Layout Reference

The default layout changed from `layouts.app` to `id::layouts.app` (provided by the new `marque/id` package).

If you were providing your own `layouts.app` and want to keep using it:

```env
GUISE_LAYOUT=layouts.app
```

Or in `config/guise.php`:
```php
'layout' => 'layouts.app',
```

## Step 10: Clear Caches and Test

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

Verify everything works:
- Tracker announce/scrape responds correctly
- Web UI loads with correct layout
- API endpoints return expected responses
- User stats are being tracked

## New Optional Packages

v2.0 introduces packages you can add if needed:

| Package | Purpose | Install |
|---|---|---|
| `marque/hound` | Public tracker (no auth required) | `composer require marque/hound` |
| `marque/disguise` | Public web frontend (browse without login) | `composer require marque/disguise` |
| `marque/usarrs` | Auth, user profiles, invites, admin panels | `composer require marque/usarrs` |

These are independent additions - install only what you need.
