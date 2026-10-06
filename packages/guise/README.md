# Marque Guise

Livewire web frontend for the [Marque](https://github.com/letterofmarque/marque) tracker platform. Provides torrent browsing, uploading, and management UI built with Livewire and Tailwind CSS.

## Starting from scratch?

Guise is the authenticated frontend only — it renders torrents but does not track
them or handle login. For a complete private tracker, install the set:

```bash
composer require marque/trove marque/bloodhound marque/guise marque/usarrs marque/cennad
```

That resolves `marque/threepio` and `marque/deck` for you. Verified working as a set,
2026-09-10.

Browsing without accounts is [marque/disguise](https://packagist.org/packages/marque/disguise)
instead.

## Installation

Requires [marque/trove](https://packagist.org/packages/marque/trove) and [marque/deck](https://packagist.org/packages/marque/deck), which supplies the Blade UI components.

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
| `GET /torrents/{torrent}` | Show | Any | View torrent details |
| `GET /torrents/upload` | Upload | Uploader+ | Upload a .torrent file |
| `GET /torrents/{torrent}/edit` | Edit | Owner / Moderator+ | Edit torrent metadata |
| `GET /torrents/{torrent}/download` | *(controller)* | Any | Download .torrent file |

## Components

### Torrent Index

Paginated torrent listing with live search (300ms debounce). Shows name, size, file count, seeders, leechers, uploader, and date. Search is reflected in the URL for bookmarking. When `trove.hide_dead_torrents` is on, a "Show dead torrents" toggle brings back torrents with no seeders.

### Torrent Show

Detailed view with torrent metadata (size, file count, info hash, uploader, upload time). Includes download button when a .torrent file is available, and an edit button for authorised users. When `marque/parley` is installed and its provider is loaded, the page embeds the torrent's comment thread.

### Torrent Upload

Upload form with file input (.torrent), name, and optional description. Validates file size (max 2MB) and name length (max 255 chars). Requires Uploader role or above. The installed tracker's rules are checked before anything is stored (see trove's README, "Uploads and downloads"). A refusal, such as a public torrent on a private tracker or a file that isn't a torrent, shows on the file field and says what to change. Warnings are passed on with the success message.

### Torrent Edit

Edit form for name and description. Info hash, size, and file count are immutable and displayed as read-only. Requires ownership or Moderator+ role.

### Torrent Download

Builds the member's `.torrent` with a sanitised filename: the stored info dictionary, untouched, with `announce` set to the installed tracker's URL for them (their own key on bloodhound) and `comment` linking the torrent's page. Gated by the `view` policy. Returns 404 if no file is stored, and 403 with the reason if the tracker has no announce URL for the member (no key yet). With no tracker installed, the stored file is served as it is.

## Configuration

Published to `config/guise.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `layout` | `deck::layouts.app` | Blade layout for full-page components |
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

Views are published to `resources/views/vendor/guise/`. All views use `marque/deck`'s Blade components and Tailwind CSS with dark mode support.

## Livewire Component Names

If you need to reference the components directly:

| Component | Name |
|-----------|------|
| Index | `guise-torrent-index` |
| Show | `guise-torrent-show` |
| Upload | `guise-torrent-upload` |
| Edit | `guise-torrent-edit` |

## Requirements

- PHP 8.3+
- Laravel 13+
- Livewire 4+
- Tailwind CSS
- [marque/trove](https://packagist.org/packages/marque/trove)
- [marque/deck](https://packagist.org/packages/marque/deck)

## License

MIT. See [LICENSE](LICENSE).
