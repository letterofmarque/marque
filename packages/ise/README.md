# Marque Ise

App layout shell and shared Blade UI components for the [Marque](https://github.com/letterofmarque/marque) tracker platform.

`ise` provides the page shell (layout, navigation, footer) and a small set of Blade
components used by the Marque frontend packages — `guise`, `disguise`, `usarrs`, and
`parley`. The name is the literal shared suffix of `gu-ise` and `dis-guise`, the two
packages it was built to serve.

> Formerly published as `marque/id`. That name collided conceptually with `usarrs`
> (user management) despite having nothing to do with auth or identity — renamed to
> `marque/ise` to stop that confusion at the source rather than document around it.
> `marque/id` is marked abandoned on Packagist, pointing here.

There is no UI-kit dependency. The components are plain Blade and Tailwind CSS, so
consumers can publish and restyle them without forking views.

## Installation

```bash
composer require marque/ise
```

Publish the config and views:

```bash
php artisan vendor:publish --tag=ise-config
php artisan vendor:publish --tag=ise-views
```

Published views land in `resources/views/vendor/ise` and override the packaged ones.

## Components

All components live under the `ise::` namespace.

| Component | Purpose |
|-----------|---------|
| `<x-ise::button>` | Button or link. `variant` (default, primary, outline, ghost, danger), `size` (sm, base, lg), `icon`, `iconTrailing`, `href` |
| `<x-ise::input>` | Text input. `type`, optional leading `icon` |
| `<x-ise::textarea>` | Multi-line input. `rows` |
| `<x-ise::field>` | Groups label + control + validation error. `label`, `name` |
| `<x-ise::label>` | Standalone label. `for` |
| `<x-ise::error>` | Validation error. Pass `name`, or content via the slot |
| `<x-ise::heading>` | Headings. `size` (sm, base, lg, xl, 2xl), optional `level` to force the tag |
| `<x-ise::text>` | Body text. `as` to change the tag |
| `<x-ise::table>` | Scroll container plus base table styling. Use standard `thead`/`tbody`/`tr`/`td` inside |
| `<x-ise::icon>` | Inline Heroicon by `name` |

Passing `name` to `<x-ise::field>` renders the label and wires up the validation error:

```blade
<x-ise::field :label="__('Name')" name="name">
    <x-ise::input wire:model="name" required />
</x-ise::field>
```

Any extra attributes (including `wire:model`, `class`, `required`) pass through to the
underlying element, and `class` merges with the component's own classes.

### Icons

`<x-ise::icon>` ships the Heroicons used by the Marque views — `arrow-left`,
`arrow-down-tray`, `magnifying-glass`, `pencil`, `plus` — inlined as SVG to avoid an
icon-package dependency. Add more by extending `resources/views/components/icon.blade.php`.

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

- PHP 8.4+
- Laravel 13+
- Livewire 4+
- Tailwind CSS

## License

MIT
