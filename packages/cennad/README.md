# Marque Cennad

REST API for the [Marque](https://github.com/letterofmarque/marque) tracker platform. Provides JSON endpoints for torrent management.

## Installation

Requires [marque/trove](https://packagist.org/packages/marque/trove).

```bash
composer require marque/cennad
```

Publish the config:

```bash
php artisan vendor:publish --tag=cennad-config
```

## Endpoints

All endpoints require authentication by default and return JSON. Read endpoints can be
opened to guests for a public tracker — see [Authentication](#authentication).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/torrents` | List torrents (paginated, searchable) |
| GET | `/api/torrents/{id}` | Get torrent details |
| POST | `/api/torrents` | Upload a torrent (Uploader+) |
| PUT | `/api/torrents/{id}` | Update torrent (name, description) |
| DELETE | `/api/torrents/{id}` | Delete torrent |

### List Torrents

```
GET /api/torrents?search=ubuntu&page=2
```

Returns paginated results with standard Laravel pagination metadata.

### Get Torrent

```
GET /api/torrents/1
```

Response:

```json
{
    "data": {
        "id": 1,
        "info_hash": "a1b2c3...",
        "name": "Example Torrent",
        "description": "Description text",
        "size": 734003200,
        "size_formatted": "700 MB",
        "file_count": 12,
        "has_torrent_file": true,
        "created_at": "2026-01-15T10:30:00Z",
        "updated_at": "2026-01-15T10:30:00Z",
        "user": {
            "id": 1,
            "name": "uploader"
        },
        "links": {
            "self": "https://example.com/api/torrents/1",
            "download": "https://example.com/torrents/1/download"
        }
    }
}
```

### Update Torrent

```
PUT /api/torrents/1
Content-Type: application/json

{
    "name": "Updated Name",
    "description": "Updated description"
}
```

Requires ownership or Moderator+ role.

### Delete Torrent

```
DELETE /api/torrents/1
```

Requires Moderator+ role. Returns `204 No Content`.

## Authorization

Cennad uses Trove's `TorrentPolicy` for access control:

| Action | Who Can |
|--------|---------|
| List / View | Any authenticated user |
| Update | Torrent owner or Moderator+ |
| Delete | Moderator+ |

## Configuration

Published to `config/cennad.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `prefix` | `api` | URL prefix for all endpoints |
| `read_middleware` | `['api', 'auth:api']` | Middleware for `index` and `show` |
| `write_middleware` | `['api', 'auth:api']` | Middleware for `store`, `update`, `destroy` |
| `route_names.prefix` | `cennad` | Route name prefix |
| `route_names.download` | `torrents.download` | Download route name (for link generation) |
| `rate_limit` | `60` | Requests per minute |

`public_middleware` and `protected_middleware` are the pre-4.0 names for `read_middleware`
and `write_middleware`. They still work and still take precedence, but they emit a
deprecation notice and are removed in 5.0.

## Authentication

Cennad uses Laravel's standard `auth:api` guard. Configure authentication in your application - Sanctum, Passport, or any guard that satisfies `auth:api` will work.

Reads and writes are configured separately so a public tracker can expose its catalogue
without exposing its write endpoints. **Both default to requiring authentication**, because
Cennad cannot tell whether it is serving a private tracker (bloodhound/guise) or a public
one (hound/disguise), and the safe assumption is the private one.

To open the catalogue to unauthenticated visitors, drop the guard from `read_middleware`:

```php
'read_middleware' => ['api'],
```

Leave `write_middleware` alone when you do — uploads, edits, and deletes are still
governed by Trove's `TorrentPolicy` on top of whatever middleware you set.

## Requirements

- PHP 8.3+
- Laravel 13+
- [marque/trove](https://packagist.org/packages/marque/trove)

## License

MIT
