# Marque Deck

App layout shell and shared Blade UI components for the [Marque](https://github.com/letterofmarque/marque) tracker platform.

`deck` provides the page shell (layout, navigation, footer) and a small set of Blade
components used across the Marque frontend packages — `guise`, `disguise`, `usarrs`,
`parley`, `squidink`, `taxonomy` and `skipper`. It is the surface everything else
stands on, which is where the name comes from.

> Formerly published as `marque/ise`, and `marque/id` before that. `ise` was the shared
> suffix of `gu-ise` and `dis-guise` — accurate when those were its only two consumers,
> and misleading once it became the shell the whole suite builds on. See
> [the upgrade guide](../../docs/upgrade-guide-ise-to-deck.md); `marque/ise` is
> abandoned on Packagist, pointing here.

There is no UI-kit dependency. The components are plain Blade and Tailwind CSS, so
consumers can publish and restyle them without forking views.

## Installation

```bash
composer require marque/deck
```

Publish the config and views:

```bash
php artisan vendor:publish --tag=deck-config
php artisan vendor:publish --tag=deck-views
```

Published views land in `resources/views/vendor/deck` and override the packaged ones.

## Components

All components live under the `deck::` namespace.

| Component | Purpose |
|-----------|---------|
| `<x-deck::button>` | Button or link. `variant` (default, primary, outline, ghost, danger), `size` (sm, base, lg), `icon`, `iconTrailing`, `href` |
| `<x-deck::input>` | Text input. `type`, optional leading `icon` |
| `<x-deck::textarea>` | Multi-line input. `rows` |
| `<x-deck::field>` | Groups label + control + validation error. `label`, `name` |
| `<x-deck::label>` | Standalone label. `for` |
| `<x-deck::error>` | Validation error. Pass `name`, or content via the slot |
| `<x-deck::heading>` | Headings. `size` (sm, base, lg, xl, 2xl), optional `level` to force the tag |
| `<x-deck::text>` | Body text. `as` to change the tag |
| `<x-deck::table>` | Scroll container plus base table styling. Use standard `thead`/`tbody`/`tr`/`td` inside |
| `<x-deck::icon>` | Inline Heroicon by `name` |

Passing `name` to `<x-deck::field>` renders the label and wires up the validation error:

```blade
<x-deck::field :label="__('Name')" name="name">
    <x-deck::input wire:model="name" required />
</x-deck::field>
```

Any extra attributes (including `wire:model`, `class`, `required`) pass through to the
underlying element, and `class` merges with the component's own classes.

### Icons

`<x-deck::icon>` ships the Heroicons used by the Marque views — `arrow-left`,
`arrow-down-tray`, `magnifying-glass`, `pencil`, `plus` — inlined as SVG to avoid an
icon-package dependency. Add more by extending `resources/views/components/icon.blade.php`.

## Navigation

The navigation renders whatever packages have registered — it names no Marque package and
holds no list of its own, so a package we have never heard of appears in the nav exactly as
a first-party one does.

Register from your own service provider's `boot()`, with a dependency on `marque/trove`
alone:

```php
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;

$this->app->make(NavRegistry::class)->register(new NavItem(
    identifier: 'acme-stats',
    label: 'Stats',
    route: 'acme.stats.index',
    icon: 'chart-bar',
    position: 40,
    // Visibility is an arbitrary rule, not just a role — "show this only if the
    // user has any invites left" is expressible. Omit it to show the item to
    // everyone, guests included.
    visible: fn (?object $user): bool => $user !== null,
));
```

A role gate is the common case and has a helper:

```php
NavItem::forRole('acme-admin', 'Acme Admin', 'acme.admin', Role::Admin);
```

Items are ordered by `position`, then label. Registering a duplicate `identifier` throws
rather than silently replacing the existing entry.

**Admin screens are a separate registry** — see
[`marque/skipper`](../skipper/README.md) and `AdminScreenRegistry`. A nav item is a
top-level link evaluated per request; an admin screen is a routed panel entry with a role
floor. Same idea, different lifecycles, deliberately not one abstraction.

## Styling

Components use Tailwind utility classes with `dark:` variants throughout. Make sure the
package views are covered by your Tailwind content paths:

```js
// tailwind.config.js
content: [
    './vendor/marque/**/resources/views/**/*.blade.php',
]
```

## Requirements

- PHP 8.3+
- Laravel 13+
- Livewire 4+
- Tailwind CSS

## License

MIT
