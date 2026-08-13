# Marque Id

App layout shell and shared Blade UI components for the [Marque](https://github.com/letterofmarque/marque) tracker platform.

`id` provides the page shell (layout, navigation, footer) and a small set of Blade
components used by the Marque frontend packages — `guise`, `disguise`, and `usarrs`.

There is no UI-kit dependency. The components are plain Blade and Tailwind CSS, so
consumers can publish and restyle them without forking views.

## Installation

```bash
composer require marque/id
```

Publish the config and views:

```bash
php artisan vendor:publish --tag=id-config
php artisan vendor:publish --tag=id-views
```

Published views land in `resources/views/vendor/id` and override the packaged ones.

## Components

All components live under the `id::` namespace.

| Component | Purpose |
|-----------|---------|
| `<x-id::button>` | Button or link. `variant` (default, primary, outline, ghost, danger), `size` (sm, base, lg), `icon`, `iconTrailing`, `href` |
| `<x-id::input>` | Text input. `type`, optional leading `icon` |
| `<x-id::textarea>` | Multi-line input. `rows` |
| `<x-id::field>` | Groups label + control + validation error. `label`, `name` |
| `<x-id::label>` | Standalone label. `for` |
| `<x-id::error>` | Validation error. Pass `name`, or content via the slot |
| `<x-id::heading>` | Headings. `size` (sm, base, lg, xl, 2xl), optional `level` to force the tag |
| `<x-id::text>` | Body text. `as` to change the tag |
| `<x-id::table>` | Scroll container plus base table styling. Use standard `thead`/`tbody`/`tr`/`td` inside |
| `<x-id::icon>` | Inline Heroicon by `name` |

Passing `name` to `<x-id::field>` renders the label and wires up the validation error:

```blade
<x-id::field :label="__('Name')" name="name">
    <x-id::input wire:model="name" required />
</x-id::field>
```

Any extra attributes (including `wire:model`, `class`, `required`) pass through to the
underlying element, and `class` merges with the component's own classes.

### Icons

`<x-id::icon>` ships the Heroicons used by the Marque views — `arrow-left`,
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
