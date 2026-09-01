# Changelog

All notable changes to `marque/usarrs` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

## [6.1.0] — 2026-09-02

### Fixed

- **Lockout:** usarrs never re-registered Fortify's email-verification or
  password-confirmation routes after v6.0.0 suppressed Fortify's own routes
  unconditionally — leaving Laravel's stock `verified` and `password.confirm`
  middleware permanently unsatisfiable for any unverified user, with no route to
  fix that. Notably, usarrs' own `admin_middleware` default
  (`['web', 'auth', 'verified']`) was itself internally inconsistent as a result.
  Found via a cold-upgrade test (job #10602). New `EmailVerificationController`
  (`verification.notice`/`verification.verify`/`verification.send`) and
  `PasswordConfirm` Livewire component (`password.confirm`) close the gap — same
  route names and behaviour a stock Fortify app would have provided. See
  [Marque 4.3](../../docs/releases/4.3.md) for the full story.

### Added

- Both new surfaces are gated by `manage_auth`, same as the rest of the auth
  surface.

## [6.0.0] — 2026-09-01

### Added

- Two-factor authentication (TOTP), off by default
  (`config('usarrs.two_factor.enabled')`, `USARRS_2FA_ENABLED`). Uses Fortify's own
  action classes via new `TwoFactorSetup` and `TwoFactorChallenge` Livewire
  components. Requires `Laravel\Fortify\TwoFactorAuthenticatable` on your `User`
  model.
- Passkeys (WebAuthn), off by default (`config('usarrs.passkeys.enabled')`,
  `USARRS_PASSKEYS_ENABLED`). Uses `laravel/passkeys` via a new
  `PasskeyManagement` Livewire component. Requires
  `Laravel\Passkeys\PasskeyAuthenticatable` and
  `Laravel\Passkeys\Contracts\PasskeyUser` on your `User` model.
- `manage_auth` escape hatch (`config('usarrs.manage_auth')`,
  `USARRS_MANAGE_AUTH`, default `true`). When `false`, usarrs registers none of its
  own auth routes or Livewire components — documented as a one-way operational
  decision for a fully custom auth implementation. Profile, invites, admin, and
  announce-key management are unaffected either way.

### Changed

- **Breaking:** now requires `laravel/fortify` (`^1.37`) as a hard dependency
  (previously only used if the host app installed it separately).
  `Fortify::ignoreRoutes()` is now called unconditionally, closing a route
  collision where Fortify's own `/login`/`/register` could stay reachable
  underneath usarrs' `auth_driver` restrictions. See
  [Marque 4.2](../../docs/releases/4.2.md) and the
  [full upgrade guide](../../docs/upgrade-guide-usarrs-v6.md).
- New migrations: two-factor columns on the users table, and a `passkeys` table
  (sourced from Fortify's and `laravel/passkeys`' own publishable migrations).

## [5.0.0] — 2026-08-26

### Changed

- **Breaking:** the profile page's `PasskeyManagement` component is renamed
  to `AnnounceKeyManagement`, along with its route, Livewire tag, and the
  `show_passkey`/`allow_passkey_regen` config keys (now
  `show_announce_key`/`allow_announce_key_regen`). See
  [Marque 4.1](../../docs/releases/4.1.md) and the
  [full upgrade guide](../../docs/upgrade-guide-bloodhound-v4-usarrs-v5.md).

## [4.0.0] — 2026-08-20

### Changed

- **Breaking:** now depends on `marque/ise` instead of `marque/id`. See
  [Marque 4.0](../../docs/releases/4.0.md).

## [3.0.0] — 2026-08-13

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
