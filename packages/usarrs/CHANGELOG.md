# Changelog

All notable changes to `marque/usarrs` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[RELEASES.md](../../RELEASES.md) for the story up to this point.

## [6.2.0] — 2026-09-04

> Lowers the PHP floor to 8.3, matching Laravel 13's own requirement.

### Changed

- **`php` constraint widened from `^8.4` to `^8.3`.** Nothing in this package
  ever required 8.4 — no property hooks, no asymmetric visibility, none of the
  8.4 array or `mb_*` functions — and Laravel 13 itself only requires `^8.3`.
  The old floor turned away working Laravel 13 apps for no technical reason.

  Lowering a floor never breaks an existing install: if you are on 8.4 you stay
  on 8.4 and nothing changes.

- Dev-only: the test suite moved from Pest 5 to Pest 4, because Pest 5 requires
  PHP 8.4 and so made the floor untestable. The suite uses only `it`/`test`/
  `expect`/`describe`/`beforeEach`, which are identical across both. No effect
  on consumers — `require-dev` is not installed downstream.

## [6.1.2] — 2026-09-04

> Fixes the passkeys migration assuming an `App\Models\User` class that need not exist.

### Fixed

- **The passkeys migration failed on any app without an `App\Models\User` class.**
  It resolved the foreign key via `Passkeys::userModel()`, but usarrs only sets that
  static when `usarrs.passkeys.enabled` is true — and it defaults to false. With
  passkeys off, the migration still ran and still read the static, getting
  `laravel/passkeys`' own default of `App\Models\User`: a class usarrs cannot assume
  a consumer has, whatever `trove.user_model` points at.

  It now reads `trove.user_model` from config, the same source the service provider
  uses, so it no longer depends on a feature flag being on.

  This was invisible because `orchestra/testbench` 11.4.0 ships an `App\Models\User`
  stub. On 11.3.5 — within the `^11.0` range usarrs declares — every test in the
  package failed. Found by a `--prefer-lowest` CI matrix run, which is also what
  guards it: the migration runs once during `RefreshDatabase`, before any test body
  executes, so nothing in-process can reproduce the condition.

## [6.1.1] — 2026-09-03

> Widens the `marque/trove` constraint to allow trove 4.x. No functional change.

### Changed

- `marque/trove` constraint widened to `^3.0|^4.0`. Trove 4.0 changes
  `TorrentServiceInterface` signatures and removes a column, neither of which
  usarrs touches — but Composer would otherwise refuse to install usarrs
  alongside the rest of the suite. Nothing in this package behaves differently.

## [6.1.0] — 2026-09-02

> Fixes a lockout where unverified users had no route to verify, leaving `verified` middleware unsatisfiable.

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

> Requires Fortify; adds off-by-default two-factor auth and passkeys, plus a `manage_auth` escape hatch.

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

> Renames the profile page's `PasskeyManagement` component.

### Changed

- **Breaking:** the profile page's `PasskeyManagement` component is renamed
  to `AnnounceKeyManagement`, along with its route, Livewire tag, and the
  `show_passkey`/`allow_passkey_regen` config keys (now
  `show_announce_key`/`allow_announce_key_regen`). See
  [Marque 4.1](../../docs/releases/4.1.md) and the
  [full upgrade guide](../../docs/upgrade-guide-bloodhound-v4-usarrs-v5.md).

## [4.0.0] — 2026-08-20

> Depends on `marque/ise` instead of the renamed `marque/id`.

### Changed

- **Breaking:** now depends on `marque/ise` instead of `marque/id`. See
  [Marque 4.0](../../docs/releases/4.0.md).

## [3.0.0] — 2026-08-13

> Raises the floor to PHP 8.4 and Laravel 13.

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
