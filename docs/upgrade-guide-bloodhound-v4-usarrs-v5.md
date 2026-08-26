# Upgrading to Bloodhound v4.0 / Usarrs v5.0

> This is the technical, step-by-step checklist. For the plain-language "what
> is this and do I need to care" version, see
> [Marque 4.1](releases/4.1.md).

This guide covers the `passkey` → `announce_key` rename. It's a breaking change
across both packages, done to remove a naming collision with Laravel's own
"passkey" (WebAuthn, via Fortify 1.38 + `laravel/passkeys`) — a feature every
Marque site can now turn on, since every Marque site is a Laravel app.

BitTorrent's "passkey" and WebAuthn's "passkey" are unrelated concepts that
happened to share a name. The tracker concept is renamed to `announce_key` —
the term is unambiguous, and BitTorrent/private-tracker users already
recognise it. Nothing about WebAuthn is added by this release; this just
clears the name for it.

If you haven't installed bloodhound or usarrs yet, or haven't gone live with
either, there's nothing to migrate — just install the new versions.

## Before You Start

1. Back up your database.
2. Note any custom overrides of bloodhound/usarrs config, views, or migrations.
3. Clear your application cache: `php artisan cache:clear`.

## Step 1: Update Composer Dependencies

```bash
composer require marque/bloodhound:^4.0 marque/usarrs:^5.0
```

## Step 2: Run the Rename Migration

Bloodhound ships a new migration that renames the column in place —
`passkey` → `announce_key` — rather than dropping and recreating it. Existing
announce keys are preserved; no user needs a new one.

```bash
php artisan migrate
```

If you publish migrations rather than loading them from the package, publish
this one too:

```bash
php artisan vendor:publish --tag=bloodhound-migrations
php artisan migrate
```

The migration is a no-op if the column is already named `announce_key` or
`passkey` is missing entirely, so it's safe to run even on a fresh install
that never had the old column.

## Step 3: Update Config Keys

`config/usarrs.php`:

| Old key | New key |
|---|---|
| `profile.show_passkey` | `profile.show_announce_key` |
| `profile.allow_passkey_regen` | `profile.allow_announce_key_regen` |

If you've published and customised `config/usarrs.php`, rename the keys
(the values keep their meaning — just carry over `true`/`false`).

## Step 4: Update Namespace / Class References

If you reference these directly (e.g. custom routes, Livewire component
overrides, or `Livewire::component()` calls):

| Old | New |
|---|---|
| `Marque\Usarrs\Livewire\Profile\PasskeyManagement` | `Marque\Usarrs\Livewire\Profile\AnnounceKeyManagement` |
| Livewire tag `usarrs-passkey-management` | `usarrs-announce-key-management` |

## Step 5: Update Model / Trait Usage

If your `User` model or any code calls these directly:

| Old | New |
|---|---|
| `$user->passkey` | `$user->announce_key` |
| `$user->generatePasskey()` | `$user->generateAnnounceKey()` |
| `$user->regeneratePasskey()` | `$user->regenerateAnnounceKey()` |

## Step 6: Update Published Views

If you've published `usarrs-views` and customised `profile/stats.blade.php`,
merge in the upstream changes: the `$showPasskey` prop is now
`$showAnnounceKey`, `$user->passkey` is now `$user->announce_key`, and the
`regeneratePasskey` Livewire action is now `regenerateAnnounceKey`.

## Step 7: Update Tracker URLs (If Hardcoded Anywhere)

The announce/scrape route parameter is renamed but the URL shape is
unchanged — `{passkey}` is now `{announce_key}` internally, and the actual
key value in a client's announce URL doesn't change (it's the same 32-char
string, just under a different column and route-parameter name). No action
needed for existing `.torrent` files or client configs — they'll keep
working against the same URLs.

If you construct announce URLs manually anywhere in your app (rather than
letting the client build them from the `.torrent` file), update any code
that names the parameter `passkey`.

## Step 8: Clear Caches and Test

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

Verify:
- Existing users' announce keys still work against `/announce/{key}`
- The profile stats page shows "Announce Key" (not "Passkey")
- Regenerating an announce key still works
- If you've since enabled Fortify/WebAuthn passkeys, confirm there's no
  longer any name overlap in your own UI copy
