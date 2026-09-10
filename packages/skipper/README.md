# Marque Skipper

The admin panel for [Marque](https://github.com/letterofmarque/marque). Renders the admin
screens other packages register — and knows nothing about any of them.

## Install

```bash
composer require marque/skipper
```

The panel appears at `/admin`, named `admin.index`.

## The idea

Skipper does not own any admin screens. Packages declare theirs against
`marque/trove`'s `AdminScreenRegistry`, and skipper renders whatever is there.

That direction matters: **nothing depends on skipper.** A package registering a screen
depends only on trove, which every Marque deployment already installs, so it behaves
identically whether or not the panel is present. Install skipper and the screens appear;
leave it out and nothing breaks. That is what makes third-party admin screens possible at
all.

## Registering a screen

From your own service provider's `boot()`:

```php
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;

$this->app->make(AdminScreenRegistry::class)->register(new AdminScreen(
    identifier: 'client-whitelist',
    label: 'Client Whitelist',
    component: 'my-package-client-whitelist',   // a Livewire component
    path: 'admin/clients',
    minimumRole: Role::Moderator,
    icon: 'shield-check',
    group: 'Tracker',
    position: 20,
));
```

| Field | Purpose |
|---|---|
| `identifier` | Unique across all packages. The route name is derived as `admin.<identifier>` |
| `label` | What the panel shows |
| `component` | The Livewire component that renders the screen |
| `path` | Where it lives |
| `minimumRole` | The floor. Enforced on the listing **and** on the request |
| `icon` | Optional, rendered from `deck`'s icon set |
| `group` | Optional heading. Ungrouped screens fall under `skipper.default_group` |
| `position` | Ordering within the group. Lower first |

Registering a duplicate `identifier` throws rather than silently replacing the existing
screen — an accidental collision should fail at boot, not render the wrong thing months
later.

Registration is legal any time up to `booted`, so it does not matter whether your provider
boots before or after skipper's.

## Stability

**The registration contract is public API from 1.0.0.** `Marque\Trove\Registry\` —
`AdminScreen`, `AdminScreenRegistry`, and their public surface — follows semver on
`marque/trove`. Build against it.

What is *not* covered by that promise: how skipper renders the panel. The views, the
grouping presentation and the layout are skipper's own surface and version with skipper.
Your screen is a Livewire component you own; the panel just lists and routes it.

## Permissions

`minimumRole` is checked twice, against the same registry entry: once when building the
panel listing, and once when the request is authorised. Filtering a menu is not protecting
a screen — without the second check, a moderator reaches an admin screen by typing its URL.

Roles come from trove (`user` → `uploader` → `moderator` → `admin`), and a screen is visible
to any role at or above its floor.

## Config

```bash
php artisan vendor:publish --tag=skipper-config
```

| Key | Default | Notes |
|---|---|---|
| `middleware` | `['web', 'auth', 'verified']` | Who gets through the door. Authorisation is separate |
| `prefix` | `admin` | Where the panel index lives |
| `title` | `Administration` | Panel heading |
| `layout` | `deck::layouts.app` | Point at your own to reshape the chrome |
| `default_group` | `General` | Heading for screens that declare no group |

## Views

```bash
php artisan vendor:publish --tag=skipper-views
```

Published views land in `resources/views/vendor/skipper` and override the packaged ones.

## Requirements

PHP 8.3+, Laravel 13+, `marque/trove`, `marque/deck`, Livewire 4.
