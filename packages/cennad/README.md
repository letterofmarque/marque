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

All endpoints require authentication and return JSON.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/torrents` | List torrents (paginated, searchable) |
| GET | `/api/torrents/{id}` | Get torrent details |
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
| `middleware` | `['api', 'auth:api']` | Middleware stack |
| `route_names.prefix` | `cennad` | Route name prefix |
| `route_names.download` | `torrents.download` | Download route name (for link generation) |
| `rate_limit` | `60` | Requests per minute |

## Authentication

Cennad uses Laravel's standard `auth:api` guard. Configure authentication in your application - Sanctum, Passport, or any guard that satisfies `auth:api` will work.

## Requirements

- PHP 8.2+
- Laravel 12+
- [marque/trove](https://packagist.org/packages/marque/trove)

## License

MIT
