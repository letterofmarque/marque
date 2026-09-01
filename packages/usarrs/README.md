# Marque Usarrs

User management — registration, login, roles, invites, profile, 2FA, and passkeys —
for the [Marque](https://github.com/letterofmarque/marque) tracker platform.

`usarrs` owns the entire auth surface end to end: login, registration, password
reset, magic links, OAuth (Socialite), logout, session handling, role-based access
control, invites, and the admin user panel. It requires
[`laravel/fortify`](https://github.com/laravel/fortify) as a hard dependency and
uses its action classes for two-factor authentication and WebAuthn passkeys — but
`usarrs` is always the only thing that registers `/login`, `/register`, and the rest
of the auth surface. Fortify's own routes are never reachable
(`Fortify::ignoreRoutes()` is called unconditionally, regardless of any other
config); it's used purely as a library.

## Installation

```bash
composer require marque/usarrs
```

Installing `marque/usarrs` pulls in `laravel/fortify` transitively — no separate
`composer require laravel/fortify` step needed.

Publish the config, views, and migrations:

```bash
php artisan vendor:publish --tag=usarrs-config
php artisan vendor:publish --tag=usarrs-views
php artisan vendor:publish --tag=usarrs-migrations
php artisan migrate
```

## Auth Driver

`config('usarrs.auth_driver')` controls the top-level login/registration flow. Set
via `USARRS_AUTH_DRIVER`, default `password`. Exactly one of these four is active at
a time:

| Driver | What it enables | What it disables |
|---|---|---|
| `password` | Email + password login and registration | — |
| `magic_link` | Passwordless email-only login. A link is emailed on request; visiting it logs the user in | The password field on login; password-based registration still creates an account, but sign-*in* afterwards is link-only |
| `socialite` | OAuth provider buttons only (`config('usarrs.socialite_providers')`, default `['github']`) | Email/password login and registration entirely — the only way in is an OAuth provider |
| `invite_only` | Password login | Public registration — `GET /register` 404s. New accounts are created only via a redeemed invite (see Invites below) |

**Every driver's `GET /login`, `GET /register` etc. are usarrs' own routes.**
Fortify's independently-registered equivalents are suppressed unconditionally
(`Fortify::ignoreRoutes()`, called in `UsarrsServiceProvider::register()`) — this
holds under every `auth_driver` value, including `invite_only`, where a bare
`POST /register` against Fortify's own (unregistered) route correctly 404s/405s
rather than silently creating an account underneath the driver's restriction. This
matters because every official Laravel starter kit (React, Vue, Livewire) bundles
Fortify with its own routes active by default — installing one alongside usarrs, or
just having Fortify present for its 2FA/passkey actions, used to leave that second
front door open. See the [manage_auth](#manage_auth-escape-hatch) section below for
the full opt-out story.

## Two-Factor Authentication

Off by default (`config('usarrs.two_factor.enabled')`, `USARRS_2FA_ENABLED`). An
additional factor layered on top of whichever `auth_driver` is active, not a driver
itself — it applies the same way regardless of how the user first authenticated.

Uses Fortify's own TOTP action classes (`EnableTwoFactorAuthentication`,
`ConfirmTwoFactorAuthentication`, `GenerateNewRecoveryCodes`,
`DisableTwoFactorAuthentication`) via usarrs' own `TwoFactorSetup` (profile-mounted)
and `TwoFactorChallenge` (login-time) Livewire components. To use it, your `User`
model needs `Laravel\Fortify\TwoFactorAuthenticatable`.

## Passkeys (WebAuthn)

Off by default (`config('usarrs.passkeys.enabled')`, `USARRS_PASSKEYS_ENABLED`). Also
an additive credential type, not a driver. Uses
[`laravel/passkeys`](https://github.com/laravel/passkeys) via usarrs' own
`PasskeyManagement` Livewire component. To use it, your `User` model needs
`Laravel\Passkeys\PasskeyAuthenticatable` and must implement
`Laravel\Passkeys\Contracts\PasskeyUser`.

Unlike Fortify, Passkeys' own routes (`/passkeys/login`, `/user/passkeys/*`) are
left registered when this feature is on — they're WebAuthn-ceremony JSON endpoints
with no usarrs equivalent to collide with, called directly by usarrs' own UI via JS.
They're suppressed when the feature is off.

## `manage_auth` Escape Hatch

`config('usarrs.manage_auth')`, `USARRS_MANAGE_AUTH`, default `true`.

When `false`, usarrs registers **none** of its own auth surface — not
`routes/auth.php` (login, register, two-factor-challenge, password reset, magic
link, socialite, logout), not the `Login`/`Register`/2FA/passkey Livewire
components. Not merely a 404 behind a mount-time check: these routes and component
tags are never bound in the first place. Fortify's own routes stay suppressed
regardless. Roles, invites, admin, profile, and announce-key management are
completely unaffected by this flag in either state.

This exists for a power-user building a fully custom login/register/2FA/passkey
implementation and needing usarrs to get out of the way entirely. **It's a one-way
operational decision, not a live toggle** — flipping it back to `true` after
building custom auth will silently re-register usarrs' routes/components alongside
whatever was built, recreating exactly the kind of route collision this flag exists
to prevent. See the [upgrade guide](../../docs/upgrade-guide-usarrs-v6.md) before
using it.

## Invites

```php
'invites' => [
    'enabled' => env('USARRS_INVITES_ENABLED', false),
    'required' => env('USARRS_INVITES_REQUIRED', false),
    'max_per_user' => env('USARRS_MAX_INVITES', 3),
    'expiry_days' => env('USARRS_INVITE_EXPIRY', 7),
],
```

Independent of `auth_driver` — combine `invite_only` with `invites.enabled` for a
fully closed, invite-gated tracker, or leave invites off and use `invite_only` alone
to close registration without an invite system.

## Requirements

- PHP 8.4+
- Laravel 13+
- `laravel/fortify` ^1.37 (pulled in automatically)
- `laravel/passkeys` (pulled in automatically; only used if passkeys are enabled)
- Livewire 4+ (guarded — usarrs boots without it, but its auth/profile/admin UI needs it)

## License

MIT
