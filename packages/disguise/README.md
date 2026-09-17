# Marque Disguise

Public-facing Livewire frontend for the [Marque](https://github.com/letterofmarque/marque)
tracker platform. Guest-browsable torrent catalogue — no login required to look.

Disguise is the public counterpart to
[marque/guise](https://packagist.org/packages/marque/guise). Guise gates everything behind
`auth` and `verified`; disguise splits its routes in two, leaving browsing, viewing and
downloading open to guests while keeping upload and edit behind authentication.

## Starting from scratch?

Disguise is the frontend only — it renders torrents but does not track them. For a
complete public tracker, install the set:

```bash
composer require marque/trove marque/hound marque/disguise
```

That resolves `marque/threepio` and `marque/deck` for you.

Running a private tracker where browsing requires an account? Use
[marque/guise](https://packagist.org/packages/marque/guise) and
[marque/bloodhound](https://packagist.org/packages/marque/bloodhound) in place of disguise
and hound.

## Installation

Requires [marque/trove](https://packagist.org/packages/marque/trove) and
[marque/deck](https://packagist.org/packages/marque/deck), which supplies the layout and
Blade components.

```bash
composer require marque/disguise
```

Publish the config and views:

```bash
php artisan vendor:publish --tag=disguise-config
php artisan vendor:publish --tag=disguise-views
```

## Routes

Two groups with different middleware — this split is the package's whole reason to exist.

### Public — guests welcome

| Route | Component | Description |
|-------|-----------|-------------|
| `GET /torrents` | Index | Browse and search the catalogue |
| `GET /torrents/{torrent}` | Show | Torrent detail page |
| `GET /torrents/{torrent}/download` | *(controller)* | Download the .torrent file |

### Admin — authentication required

| Route | Component | Authorisation |
|-------|-----------|---------------|
| `GET /torrents/upload` | Upload | `create` policy — Uploader+ |
| `GET /torrents/{torrent}/edit` | Edit | `update` policy — owner or Moderator+ |

The admin group is registered **first**, deliberately: without that ordering `/torrents/upload`
would match the `{torrent}` wildcard and resolve as a torrent lookup.

Disguise registers a `Torrents` nav entry against trove's `NavRegistry` with no visibility
rule — guest browsing is the point, so the entry shows to everyone including logged-out
visitors.

## Guests are not the same as "everyone sees everything"

Public browsing does not mean unrestricted browsing. Trove's `TorrentPolicy` still runs on
every route here, and a torrent carrying a `min_role` is invisible to guests:

- **Listing** is filtered by the `visibleTo` query scope, not the policy — a policy cannot
  filter a collection. Restricted torrents simply don't appear.
- **Detail pages and downloads** go through the `view` policy. A guest hitting a restricted
  torrent's URL directly gets a 403.
- **Downloads are gated exactly as tightly as viewing**, never more loosely, because the
  .torrent file carries the announce key.

So a public tracker can still hold staff-only or uploader-only material; those rows are
just absent for anyone who shouldn't see them.

## Components

### Torrent Index

Paginated listing with live search bound to the URL (`?search=`), so results are
bookmarkable and survive a refresh. Page size is configurable.

Carries a **Show dead torrents** toggle (`?showDead=`), which only appears when
`trove.hide_dead_torrents` is on — with it off there are no hidden torrents to reveal and
the control would be meaningless.

### Torrent Show

Detail view with torrent metadata and the download button. Loads the uploading user
alongside the torrent.

### Torrent Upload

Upload form — .torrent file (max 2MB), name (max 255), optional description (max 10000).
Requires the `create` policy, i.e. Uploader role or above. Returns 404 when
`allow_upload` is off, rather than rendering a form that would fail on submit.

### Torrent Edit

Edits name and description only. Info hash, size and file count are immutable and shown
read-only. Re-authorises on save as well as on mount, so a role change mid-session cannot
be used to land an edit.

### Torrent Download

Streams the .torrent from trove's configured storage disk under a sanitised filename.
404s when `allow_download` is off or the torrent has no stored file.

## Configuration

Published to `config/disguise.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `layout` | `deck::layouts.app` | Blade layout for full-page components |
| `prefix` | *(empty)* | URL prefix for all routes |
| `public_middleware` | `['web']` | Middleware for browse, view, download |
| `admin_middleware` | `['web', 'auth']` | Middleware for upload, edit |
| `allow_upload` | `true` | Web upload enabled |
| `allow_download` | `true` | Web download enabled |
| `per_page` | `25` | Torrents per page |

`layout` and `prefix` read from the environment:

```env
DISGUISE_LAYOUT=deck::layouts.app
DISGUISE_PREFIX=tracker
```

A prefix moves everything — `/tracker/torrents`, `/tracker/torrents/upload`, and so on.

### Pointing at your own layout

By default components render inside deck's shell. To use your app's own layout, set
`DISGUISE_LAYOUT` to your Blade view. It needs `@livewireStyles` and `@livewireScripts`
unless you're relying on Livewire's auto-injection.

### Turning off upload or download

`allow_upload` and `allow_download` make the respective routes 404 rather than hiding a
link. Use them for a mirror or an archive-only deployment where the catalogue is
browsable but nothing new comes in.

Note these are blunt switches, not authorisation — the policies still apply when the
features are on.

## Customising views

```bash
php artisan vendor:publish --tag=disguise-views
```

Views land in `resources/views/vendor/disguise/` and override the packaged ones. They are
plain Blade and Tailwind CSS built on deck's components — there is no UI-kit dependency to
work around.

## Livewire component names

If you need to render one directly rather than routing to it:

| Component | Name |
|-----------|------|
| Index | `disguise-torrent-index` |
| Show | `disguise-torrent-show` |
| Upload | `disguise-torrent-upload` |
| Edit | `disguise-torrent-edit` |

## Requirements

- PHP 8.3+
- Laravel 13+
- Livewire 4+
- Tailwind CSS
- [marque/trove](https://packagist.org/packages/marque/trove)
- [marque/deck](https://packagist.org/packages/marque/deck)

## License

MIT
