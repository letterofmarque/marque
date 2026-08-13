# Marque Guise

Livewire web frontend for the [Marque](https://github.com/letterofmarque/marque) tracker platform. Provides torrent browsing, uploading, and management UI built with Livewire and Tailwind CSS.

## Installation

Requires [marque/trove](https://packagist.org/packages/marque/trove) and [marque/id](https://packagist.org/packages/marque/id), which supplies the Blade UI components.

```bash
composer require marque/guise
```

Publish the config and views:

```bash
php artisan vendor:publish --tag=guise-config
php artisan vendor:publish --tag=guise-views
```

## Routes

All routes require authentication and email verification.

| Route | Component | Role Required | Description |
|-------|-----------|---------------|-------------|
| `GET /torrents` | Index | Any | Browse and search torrents |
| `GET /torrents/{id}` | Show | Any | View torrent details |
| `GET /torrents/upload` | Upload | Uploader+ | Upload a .torrent file |
| `GET /torrents/{id}/edit` | Edit | Owner / Moderator+ | Edit torrent metadata |
| `GET /torrents/{id}/download` | *(controller)* | Any | Download .torrent file |

## Components

### Torrent Index

Paginated torrent listing with live search (300ms debounce). Shows name, size, file count, uploader, and date. Search is reflected in the URL for bookmarking.

### Torrent Show

Detailed view with torrent metadata (size, file count, info hash, uploader, upload time). Includes download button when a .torrent file is available, and an edit button for authorised users.

### Torrent Upload

Upload form with file input (.torrent), name, and optional description. Validates file size (max 2MB) and name length (max 255 chars). Requires Uploader role or above.

### Torrent Edit

Edit form for name and description. Info hash, size, and file count are immutable and displayed as read-only. Requires ownership or Moderator+ role.

### Torrent Download

Streams the .torrent file from storage with a sanitised filename. Returns 404 if no file is stored.

## Configuration

Published to `config/guise.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `layout` | `layouts.app` | Blade layout for full-page components |
| `prefix` | *(empty)* | URL prefix for routes (e.g. `tracker`) |
| `middleware` | `['web', 'auth', 'verified']` | Middleware stack |

### Layout

Guise components render inside the configured layout. Set `GUISE_LAYOUT` in your `.env` or publish the config to point to your app's layout:

```env
GUISE_LAYOUT=layouts.app
```

Your layout needs `@livewireStyles` and `@livewireScripts` (or Livewire's auto-injection if you're using it).

### Route Prefix

Add a prefix to all Guise routes:

```env
GUISE_PREFIX=tracker
```

This changes routes to `/tracker/torrents`, `/tracker/torrents/upload`, etc.

## Customising Views

Publish the views to override them:

```bash
php artisan vendor:publish --tag=guise-views
```

Views are published to `resources/views/vendor/guise/`. All views use Flux UI components and Tailwind CSS with dark mode support.

## Livewire Component Names

If you need to reference the components directly:

| Component | Name |
|-----------|------|
| Index | `guise-torrent-index` |
| Show | `guise-torrent-show` |
| Upload | `guise-torrent-upload` |
| Edit | `guise-torrent-edit` |

## Requirements

- PHP 8.2+
- Laravel 12+
- Livewire 4+
- Tailwind CSS
- [marque/trove](https://packagist.org/packages/marque/trove)
- [marque/id](https://packagist.org/packages/marque/id)

## License

MIT
