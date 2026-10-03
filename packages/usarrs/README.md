# Marque Usarrs

User management — registration, login, roles, invites, profile, 2FA, and passkeys —
for the [Marque](https://github.com/letterofmarque/marque) tracker platform.

`usarrs` owns the entire auth surface end to end: login, registration, password
reset, magic links, OAuth (Socialite), logout, session handling, role-based access
control, invites, and the admin user panel. It requires
[`laravel/fortify`](https://github.com/laravel/fortify) as a hard dependency and
uses its action classes for two-factor authentication (passkeys come from
[`laravel/passkeys`](https://github.com/laravel/passkeys)) — but
`usarrs` is always the only thing that registers `/login`, `/register`, and the rest
of the auth surface. Fortify's own routes are never reachable
(`Fortify::ignoreRoutes()` is called unconditionally, regardless of any other
config); it's used purely as a library.

## Starting from scratch?

Usarrs is the auth and user-management half — it has no catalogue and no tracker of
its own. For a complete private tracker, install the set:

```bash
composer require marque/trove marque/bloodhound marque/guise marque/usarrs marque/cennad
```

That resolves `marque/threepio` and `marque/deck` for you. Verified working as a set,
2026-09-10.

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

### Layout

usarrs' own pages (login, register, profile, admin, and everything else it renders)
use `config('usarrs.layout')`, default `deck::layouts.app`. Set `USARRS_LAYOUT` in
your `.env` or publish the config to point to your app's own layout:

```env
USARRS_LAYOUT=layouts.app
```

This is a **per-package** setting, not a suite-wide one — `guise` and `disguise`
have their own equivalent keys (`GUISE_LAYOUT`, `DISGUISE_LAYOUT`) that default the
same way but are set independently. If you're using more than one Marque frontend
package pointed at the same custom layout, set each package's key to match.

### Route middleware

Three stacks, because the auth routes divide into three genuinely different
audiences:

| Config key | Default | Applies to |
|---|---|---|
| `guest_middleware` | `['web', 'guest']` | login, register, 2FA challenge, forgot-password |
| `middleware` | `['web']` | reset-password, magic-link, OAuth redirect/callback/confirm |
| `auth_middleware` | `['web', 'auth']` | logout, profile, email verification, password confirm |

The middle row is the one worth understanding. Those routes are **not**
guest-gated on purpose: an authenticated user can legitimately follow a
password-reset link that arrived by email, click a magic link issued on
another device, or complete an OAuth round trip to connect a provider to their
account (see [OAuth](#oauth-the-socialite-driver)). Gating them would break all
three, which is why `guest` is applied to the genuinely guest-only routes rather
than to the whole group. Each still only exists under the driver it belongs to.

Override any of them by publishing the config. If you point `guest_middleware`
at a custom stack, keep a `guest`-equivalent in it or logged-in users will see
the login form again.

### Banned, disabled and pending users

A user whose `status` is anything but `active` can't sign in, and is signed out
if they already are:

- **Every sign-in path refuses them.** Password, magic link, OAuth and the
  two-factor challenge all finish in one place, which turns an inactive user
  back to the login form with the reason ("This account has been banned.").
  Ways in that don't pass through it, like passkey sign-in or a remember-me
  cookie, are caught on Laravel's `Login` event and get a 403.
- **A live session ends on its next request**, on any page in the `web`
  middleware group and not just usarrs' own. usarrs pushes
  `EnsureUserIsActive` onto that group, so a ban covers browsing and
  downloading everywhere. It also covers Livewire actions from a tab that was
  already open.

A user with no `status` at all is never refused, because an app without that
column has no concept of a ban. A status value usarrs doesn't recognise is
refused.

## Auth Driver

`config('usarrs.auth_driver')` controls the top-level login/registration flow. Set
via `USARRS_AUTH_DRIVER`, default `password`. Exactly one of these four is active at
a time:

| Driver | What it enables | What it disables |
|---|---|---|
| `password` | Email + password login and registration | — |
| `magic_link` | Passwordless email-only login. A link is emailed on request; visiting it logs the user in | Password login and password reset; password-based registration still creates an account, but sign-*in* afterwards is link-only |
| `socialite` | OAuth sign-in, via the providers in `config('usarrs.socialite_providers')` (default `['github']`) — plus passkeys, if you've enabled them (see [Passkeys](#passkeys-webauthn)). Requires `composer require laravel/socialite` | Password login, password registration, password reset and magic links — refused on the server, not just hidden. See [OAuth](#oauth-the-socialite-driver) |
| `invite_only` | Password login, and registration through an invite | Open sign-up: `GET /register` is a 404 unless it carries a valid, unused, unexpired invite (`/register?invite=CODE`, the link in the invite email). Needs `invites.enabled` so members can create invites |

Each driver's routes only exist under that driver: the OAuth routes only under
`socialite`, magic-link verification only under `magic_link`, and password reset
only under `password` and `invite_only`. A route that belongs to another driver is
a 404, not a hidden form.

**Every driver's `GET /login`, `GET /register` etc. are usarrs' own routes.**
Fortify's independently-registered equivalents are suppressed unconditionally
(`Fortify::ignoreRoutes()`, called in `UsarrsServiceProvider::register()`) — this
holds under every `auth_driver` value, including `invite_only`, where a bare
`POST /register` against Fortify's own (unregistered) route correctly 404s/405s
rather than silently creating an account underneath the driver's restriction. This
matters because every official Laravel starter kit (React, Vue, Livewire) bundles
Fortify with its own routes active by default — installing one alongside usarrs, or
just having Fortify present for its 2FA actions, used to leave that second
front door open. See the [manage_auth](#manage_auth-escape-hatch) section below for
the full opt-out story.

## OAuth (the socialite driver)

An OAuth sign-in is matched to an account by the **provider's own user id**, stored
in `usarrs_social_accounts` when the two are connected — never by email. A provider
reporting someone's email address does not make you that someone.

| Someone completes OAuth and… | What happens |
|---|---|
| the identity is connected to an account | signed in to that account — through the two-factor challenge if they have 2FA on |
| it isn't connected, but its email matches an account here (ignoring case) | nobody is signed in. That account's own address is emailed a link, valid 60 minutes and good once. The email and the page it opens name the provider account; nothing is connected until the holder presses **Connect** there, which then signs them in (2FA applies). Opening the link alone — or a mail scanner opening it — does nothing |
| it isn't connected and matches no account | an account is created **only if registration is open** — the same rules as `/register`, including required invites — then it's sent the verification email and signed in. Where registration is closed, the visitor gets the same answer whether or not the address has an account, so the callback can't be used to probe which addresses exist |
| they're already signed in | the identity is connected to *their* account. One that's connected to someone else is refused; they are never switched into another account |

**Invites with OAuth:** there's no registration form under this driver, so send the
invite through the redirect — `/auth/github/redirect?invite=CODE`. An invite is
claimed in the same transaction that creates the account, so two sign-ups racing on
one invite get one account between them.

**Connecting proves the address.** An account made by OAuth starts unverified: a
provider *reporting* an address isn't proof of owning it. So someone could make an
account under an address that isn't theirs, before its owner does. When the owner
later confirms a connection from that address's inbox, usarrs marks the address
verified and — because OAuth made the account — removes everything it gained before
then: other provider connections, passkeys, two-factor, remembered sign-ins, and any
open session (its password hash is rotated; see below). This needs your `User` model to
implement `MustVerifyEmail` (see
[Email Verification](#email-verification--password-confirmation)).

What it can't do is tell an old account from a squatted one. Accounts made before 8.1
— by OAuth, or by `/register` under another driver before a switch to `socialite` —
have no stored connection, and they're verified on confirmation but **keep** their 2FA,
passkeys and sessions. That protects every existing user's own setup on upgrade day; the
cost is that an account somebody squatted *before* 8.1 keeps what they attached. (Before
8.1 a squatter didn't need to: the callback signed anyone in by email.) If you have
reason to doubt an old account, clear its 2FA and passkeys directly — the `two_factor_*`
columns on the user and its rows in `passkeys`; there's no admin action for it yet.

An account whose address isn't verified can't connect another provider, and changing
an account's email on the profile page un-verifies it and sends the new address a
verification mail. Addresses are unique ignoring case, on the profile page and `/register`
alike.

**Put `auth.session` on your own signed-in routes.** Ending an open session works
through Laravel's `AuthenticateSession` middleware (`auth.session`), which signs out
a session whose password hash has changed underneath it. usarrs puts it on its own
signed-in routes and the OAuth routes, adds it to Livewire's persistent middleware (so
it also guards component actions in a tab that was already open), and records the hash
at every sign-in so no session escapes it. Routes your app defines need it too —
`Route::middleware(['auth', 'auth.session'])` — or a squatter can keep using them.

**The cache must be shared and persistent.** A pending connection is held in the
default cache store between the email and the confirmation. With the `array` store,
or a per-server `file` store behind a load balancer, every link reads as "already
used or expired".

**Upgrading from before 8.1:** existing OAuth accounts have no stored connection yet.
The first time each of those users signs in with OAuth, they're emailed the
connection link above; they confirm on the page it opens, and every sign-in after
that goes straight through. The email names the provider account asking to connect,
so tell your users to expect it — and to ignore one naming an account that isn't
theirs.

## Email Verification & Password Confirmation

usarrs registers both of these under the same route names Laravel's own core
primitives already expect, so `verified` and `password.confirm` middleware — including
usarrs' own `admin_middleware` default, `['web', 'auth', 'verified']` — work exactly
as they would in a stock Laravel app:

| Route name | Path | Purpose |
|---|---|---|
| `verification.notice` | `GET /email/verify` | "Check your email" prompt |
| `verification.verify` | `GET /email/verify/{id}/{hash}` | The signed link from the verification email |
| `verification.send` | `POST /email/verification-notification` | Resend the verification email |
| `password.confirm` | `GET /user/confirm-password` | Re-enter your password before a sensitive action |

**Your `User` model needs `implements \Illuminate\Contracts\Auth\MustVerifyEmail`
to use email verification** — not just the trait. This trips people up: Laravel's own
base `Illuminate\Foundation\Auth\User` class `use`s the `MustVerifyEmail` *trait*
(the methods), but does not `implements` the `MustVerifyEmail` *contract* (the
interface). `EnsureEmailIsVerified` middleware checks `instanceof` the contract, so
without the explicit `implements`, the `verified` middleware silently treats every
user as already verified and does nothing — no error, it just never blocks anyone.
If you're seeing verification routes work but the `verified` middleware never
actually stopping an unverified user, this is almost certainly why:

```php
class User extends Authenticatable implements MustVerifyEmail
{
    use \Illuminate\Auth\MustVerifyEmail; // the trait — same short name, different thing
}
```

**Every new account is sent the verification email**, whether it came from
`/register` or the OAuth callback. Both fire Laravel's `Registered` event, so
your app can hook new accounts. A stock Laravel 11+ app already listens for that
event with `SendEmailVerificationNotification`; when that listener is
registered, usarrs leaves the sending to it, and only sends the mail itself when
the listener is absent. Either way the user gets exactly one mail.

Password confirmation needs no opt-in trait — it works against any authenticated user
out of the box.

Both are part of the auth surface [manage_auth](#manage_auth-escape-hatch) governs —
absent entirely when `manage_auth=false`, same as login/register/2FA/passkey.

## Two-Factor Authentication

Off by default (`config('usarrs.two_factor.enabled')`, `USARRS_2FA_ENABLED`). An
additional factor layered on top of whichever `auth_driver` is active, not a driver
itself — it applies the same way regardless of how the user first authenticated.

Uses Fortify's own TOTP action classes (`EnableTwoFactorAuthentication`,
`ConfirmTwoFactorAuthentication`, `GenerateNewRecoveryCodes`,
`DisableTwoFactorAuthentication`) via usarrs' own `TwoFactorSetup` (profile-mounted)
and `TwoFactorChallenge` (login-time) Livewire components. To use it, your `User`
model needs `Laravel\Fortify\TwoFactorAuthenticatable`.

### Rate limits

| Where | Limit | Keyed on |
|---|---|---|
| Password login | 5 a minute | email (case-folded) and IP |
| Two-factor challenge | 5 a minute, codes and recovery codes together | the pending login |
| Password confirmation | 5 a minute | the signed-in user |

The challenge limit isn't keyed on IP. Anyone at the challenge already has the
password, and an IP key would let them switch addresses to get five more guesses.
A successful attempt resets the count.

**A TOTP code works once.** usarrs keeps the timestamp of each user's last
accepted code and only accepts a newer one. That way a code seen over someone's
shoulder or in a log can't be reused while it's still valid. Fortify's own
provider keys this on the code alone, so two users who happen to share a code
block each other. usarrs keys it per user.

The limits and the used-code record live in your app's cache store. A cache
that doesn't persist between requests, such as the `array` driver, turns both
off.

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
They're suppressed when the feature is off, and carry `auth.session` when it's on.

> **Known issue (#10883):** recent Fortify releases (1.39 at least) suppress
> `laravel/passkeys`' routes in order to serve their own — and usarrs suppresses
> Fortify's. On those versions the passkey endpoints exist nowhere, so passkey
> registration and sign-in don't work. Leave passkeys off until this is fixed.

**Passkey sign-in is the one login usarrs doesn't finish itself.** Every other way in
— password, magic link, OAuth, straight after registering — ends in one place that
applies the two-factor challenge. A passkey signs in through `laravel/passkeys`' own
endpoint, under every driver including `socialite`, and asks for no TOTP code
afterwards: a passkey is already a phishing-resistant factor, so a code on top adds
little. If you want `socialite` to mean OAuth and nothing else, leave passkeys off.

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

Two ways to run an invite-gated tracker:

- **`auth_driver=invite_only`** with `invites.enabled`. `/register` doesn't exist
  for anyone without an invite: it's a 404 unless the request carries a valid one.
  This is the fully closed shape.
- **`auth_driver=password`** with `invites.enabled` and `invites.required`. The
  form is visible to everyone but won't create an account without a valid invite.

An invite created with a recipient address is emailed **to that address**, with a
`/register?invite=CODE` link. Any valid invite presented at registration is used
up, whether or not `invites.required` is on, and the same applies to an OAuth
sign-up that carries one.

## Dashboard

`/dashboard` (`dashboard.index`) is one screen answering "how am I doing" for a signed-in
user. **The route is registered on every install**, whatever else is present, so
`route('dashboard.index')` is always safe to link to — no `Route::has()` guard needed. A
Dashboard entry is added to the navigation for signed-in users, and `marque:install`
offers it as the home page (the default for a private tracker) — in the installer on
`main`; `marque/marque` hasn't been tagged yet.

The page renders panels from trove's `DashboardPanelRegistry`. usarrs registers these, each
appearing only when it has something true to say:

| Panel | Appears when |
|---|---|
| Tracker Stats — uploaded, downloaded, ratio | a tracker has bound `TrackerStatsInterface`, and it keeps figures for this user |
| Announce Key — with regenerate | a tracker is bound, the user has a key, and `profile.show_announce_key` is on |
| Account Security — two-factor, passkeys | `two_factor.enabled` or `passkeys.enabled`, and the User model supports it; not registered when `manage_auth` is `false` |
| Invites — allowance, pending, send | `invites.enabled`, and the user can send one or has one outstanding |

Other packages add their own panels without usarrs knowing about them — see
[trove's README](../trove/README.md#dashboard-panels). With nothing to show, the page says
"Nothing to show yet" rather than rendering an empty grid; that is what a stock install
with no tracker and 2FA, passkeys and invites all off will see.

**`/profile/stats` still exists.** The dashboard shows the same figures and announce key,
and its stats panel links through to it for the full page. Removing it would remove a
named route — a major version for usarrs and a broken deep link for anyone who bookmarked
it — so in this version the two coexist. Whether it goes is a decision for later, once the
dashboard has proven itself.

## Requirements

- PHP 8.3+
- Laravel 13
- `laravel/fortify` ^1.30 (pulled in automatically)
- `laravel/passkeys` (pulled in automatically; only used if passkeys are enabled)
- Livewire 4 (pulled in automatically)

## License

MIT
