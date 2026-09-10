# Upgrade Guide: `marque/ise` → `marque/deck`

`marque/ise` has been renamed to `marque/deck`. Same package, same components, same layout —
new name and namespace. See `docs/why.md` ("Why `ise` Was Renamed to `deck`") for the
reasoning, and Spec #108 for the full decision record.

**This is a rename, not a rewrite.** No component changed behaviour, no markup changed, no
API was removed. Everything below is search-and-replace.

`marque/ise` is abandoned on Packagist, pointing at `marque/deck`. It will not receive
further releases.

## 1. Composer

```diff
-"marque/ise": "^1.0"
+"marque/deck": "^1.0"
```

```bash
composer remove marque/ise
composer require marque/deck
```

If you use a path repository in local development, update its `url` too:

```diff
-{ "type": "path", "url": "../ise" }
+{ "type": "path", "url": "../deck" }
```

## 2. Namespace

```diff
-use Marque\Ise\View\Components\Navigation;
+use Marque\Deck\View\Components\Navigation;
```

The service provider is renamed with it — `Marque\Ise\IseServiceProvider` becomes
`Marque\Deck\DeckServiceProvider`. It is auto-discovered, so you only need this if you
register providers explicitly (including in a Testbench `getPackageProviders()`).

## 3. View namespace and component tags

Every `ise::` reference becomes `deck::`:

```diff
-<x-ise::button variant="primary">Save</x-ise::button>
-<x-ise::field :label="__('Name')" name="name">
-    <x-ise::input wire:model="name" />
-</x-ise::field>
+<x-deck::button variant="primary">Save</x-deck::button>
+<x-deck::field :label="__('Name')" name="name">
+    <x-deck::input wire:model="name" />
+</x-deck::field>
```

```diff
-@extends('ise::layouts.app')
+@extends('deck::layouts.app')
```

A repo-wide replace of `ise::` → `deck::` and `x-ise::` → `x-deck::` covers this. Both
patterns are needed: `x-ise::` does not match a `\bise::` word-boundary search, because the
`-` before `ise` is not a word character. That specific gap is what made this rename take two
passes internally.

## 4. The Livewire navigation component

```diff
-<livewire:ise-navigation />
+<livewire:deck-navigation />
```

## 5. Config file — **published config needs manual attention**

The config file is renamed `ise.php` → `deck.php`, and its keys move with it:

```diff
-config('ise.app_name')
-config('ise.show_footer')
-config('ise.theme')
+config('deck.app_name')
+config('deck.show_footer')
+config('deck.theme')
```

**If you published the config, upgrading the package does not fix your copy.** You have a
`config/ise.php` in your own app that Laravel still loads under the `ise` key, and nothing
in the package will rename it for you:

```bash
mv config/ise.php config/deck.php
```

**The same trap applies to any Marque package's published config that points at the layout.**
`guise.php`, `disguise.php`, `usarrs.php` and `parley.php` each default their layout to the
shell's view namespace. In the package the default is now `deck::layouts.app`, but a
*published* copy in your app still says:

```php
'layout' => 'ise::layouts.app',   // published copy — update this by hand
```

Change it to `'deck::layouts.app'`. If you skip this, the layout silently fails to resolve
after `marque/ise` is removed.

## 6. Environment variable

```diff
-ISE_THEME=default
+DECK_THEME=default
```

## 7. Published views

The vendor view path changes:

```diff
-resources/views/vendor/ise/
+resources/views/vendor/deck/
```

```bash
mv resources/views/vendor/ise resources/views/vendor/deck
```

The publish tag changes too:

```diff
-php artisan vendor:publish --tag=ise-views
+php artisan vendor:publish --tag=deck-views
```

Config publish tag: `ise-config` → `deck-config`.

## Checklist

- [ ] `composer require marque/deck`, `composer remove marque/ise`
- [ ] Path repository `url` updated, if you use one
- [ ] `Marque\Ise` → `Marque\Deck` in PHP
- [ ] `ise::` → `deck::` **and** `x-ise::` → `x-deck::` in Blade
- [ ] `<livewire:ise-navigation />` → `<livewire:deck-navigation />`
- [ ] `config('ise.*)` → `config('deck.*)`
- [ ] Published `config/ise.php` renamed to `config/deck.php`
- [ ] **Published `guise.php` / `disguise.php` / `usarrs.php` / `parley.php` layout defaults
      changed from `ise::layouts.app` to `deck::layouts.app`**
- [ ] `ISE_THEME` → `DECK_THEME` in `.env`
- [ ] `resources/views/vendor/ise/` renamed to `.../deck/`
- [ ] Test suite green

## Verifying

```bash
grep -rn 'Marque\\Ise\|ise::\|marque/ise\|ISE_THEME' app/ config/ resources/ --exclude-dir=vendor
```

Anything this returns is still to be migrated.
