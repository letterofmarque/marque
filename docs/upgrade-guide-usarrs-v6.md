# Upgrading to Usarrs v6.0

> This is the technical, step-by-step checklist. For the plain-language "what
> is this and do I need to care" version, see
> [Marque 4.2](releases/4.2.md).

This guide covers `marque/usarrs` requiring `laravel/fortify` as a hard dependency
and adding off-by-default two-factor authentication and passkey (WebAuthn) support,
built on top of it. The short version: usarrs already owned 100% of the
login/register/auth surface, and this release adds two more auth *methods* to that
surface using Fortify's own already-audited action classes, rather than hand-rolling
TOTP and WebAuthn ceremony from scratch. As a structural side effect, it also closes
a route collision where Fortify's own `/register` and `/login` routes could stay
reachable underneath usarrs' `auth_driver` checks if an app happened to have Fortify
installed for its own reasons (every official Laravel starter kit ships it).

**If you've never installed usarrs, or haven't gone live, there's nothing to
migrate** — just install v6.0.

## Before You Start

1. Back up your database.
2. Note any custom overrides of `config/usarrs.php`, published usarrs views, or
   published usarrs migrations.
3. Clear your application cache: `php artisan cache:clear`.

## Step 1: Update Composer Dependencies

```bash
composer require marque/usarrs:^6.0
```

This pulls in `laravel/fortify` and `laravel/passkeys` automatically — no separate
`composer require laravel/fortify` step needed. If your app already had
`laravel/fortify` in `composer.json` for its own reasons, remove that explicit
requirement; usarrs now owns the version constraint.

## Step 2: If You Previously Worked Around #10583 Manually

If you added a `config/fortify.php` `array_filter()` (or similar) to suppress
Fortify's own routes because usarrs wasn't calling `Fortify::ignoreRoutes()` for
you — **remove it**. usarrs now calls `Fortify::ignoreRoutes()` unconditionally in
its own service provider, every boot, regardless of any other config. Your manual
workaround is redundant and safe to delete; leaving it in place doesn't conflict
with usarrs' own call, but there's nothing left for it to do.

## Step 3: Run the New Migrations

Two new migrations ship in this release, sourced from Fortify's and
`laravel/passkeys`' own publishable migrations: two-factor columns on the users
table, and a `passkeys` table.

```bash
php artisan migrate
```

If you publish migrations rather than loading them from the package:

```bash
php artisan vendor:publish --tag=usarrs-migrations
php artisan migrate
```

Both new toggles default off (see Step 4), so running these migrations is
behaviourally inert until you opt in — no new auth surface appears on upgrade just
because the columns/table now exist.

## Step 4: Two New Off-By-Default Config Toggles

`config/usarrs.php` gains two keys, both default `false`:

```php
'two_factor' => ['enabled' => env('USARRS_2FA_ENABLED', false)],
'passkeys'   => ['enabled' => env('USARRS_PASSKEYS_ENABLED', false)],
```

If you've published and customised `config/usarrs.php`, add both keys (or re-publish
and re-apply your customisations). Nothing changes for your users until you flip one
to `true`.

**To use 2FA:** add `Laravel\Fortify\TwoFactorAuthenticatable` to your `User` model.

**To use passkeys:** add `Laravel\Passkeys\PasskeyAuthenticatable` and implement
`Laravel\Passkeys\Contracts\PasskeyUser` on your `User` model.

Both are additive traits — the existing pattern usarrs already uses for
`HasRoles`/`HasTrackerStats` — not a change to `trove`'s `UserInterface` contract.

## Step 5: The `manage_auth` Escape Hatch (New, Also Off — i.e. `true` by Default)

A third new config key, default `true` (meaning: usarrs behaves exactly as before
unless you change it):

```php
'manage_auth' => env('USARRS_MANAGE_AUTH', true),
```

Only relevant if you plan to replace usarrs' login/register/2FA/passkey UI with
something fully custom. Setting it `false` stops usarrs from registering any of its
own auth routes or Livewire components — not gated behind a 404, genuinely never
bound. Everything else (roles, invites, admin panel, profile, announce-key
management) keeps working normally regardless of this flag.

**This is a one-way *operational* decision, not a live toggle.** If you build custom
auth and later flip `manage_auth` back to `true`, usarrs will silently re-register
its own routes/components alongside whatever you built — recreating the exact kind
of route collision this release exists to close. Nothing in code prevents flipping
it back; it's on you to not do that once you've committed to the custom path. Most
installations will never touch this key.

## Step 6: Clear Caches and Test

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

Verify:
- Existing login/registration/password-reset/logout still work exactly as before
  (nothing here changes default behaviour if you don't touch the new config keys).
- `POST /register` and `POST /login` return a 405 (not a 200/302 creating a user or
  session) — confirms Fortify's own routes are suppressed. If you'd previously
  verified job #10583's hole was closed via your own manual workaround, this is the
  same check, now true by construction rather than by your app's own config.
- If you enable 2FA: setup, confirmation, and challenge-at-login all work.
- If you enable passkeys: registration and login via WebAuthn work (needs a real
  browser + authenticator — this can't be scripted headlessly).
