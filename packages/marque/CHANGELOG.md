# Changelog

All notable changes to `marque/marque` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-10-01

> First release of the installer: one composer require, then `php artisan marque:install` interviews you and wires a working tracker.

### Added

- **The package itself**, and the `marque:install` command it exists to carry.

  `composer require` previously left an app looking completely unchanged: the
  suite registered thirty-odd working routes while `/` was still Laravel's
  welcome page, `/torrents` fatalled on a stock `User` model, and no package CSS
  compiled at all because nothing added the Tailwind `@source` lines. Everything
  worked and nothing announced itself.

  This package is the front door that fixes that. It requires only the four
  packages every deployment needs — `trove`, `threepio`, `deck`, `usarrs` — and
  `marque:install` adds the rest based on what you are actually building.

- **The require block is asserted by a test, not documented by a comment.**
  `marque/hound` registers an open `announce` route with no key and no auth,
  unconditionally. A private tracker that merely has hound in `vendor/` carries a
  keyless announce endpoint beside its authenticated one, with ratio accounting
  bypassed. So the wrong tracker package is never installed rather than being
  disabled by config, and a future edit adding one here fails `ManifestTest`.

- **One run finishes the job.** composer require runs in a subprocess, and the process
  that started it can't load what it just installed, or the User model it just patched.
  So after the file edits (stylesheet, User model, home page), the rest (config,
  migrations, the admin account, the self-check) runs in a fresh `php artisan` process,
  `marque:install:finish`, which boots with all of it loaded.
- **The User model patch adds `MustVerifyEmail`** (uncommenting Laravel's own import), so
  the `verified` checks are real: usarrs' /admin, and its proof that an OAuth sign-in's
  owner holds the inbox. The diff screen says how many existing users haven't verified.
- **A re-run starts from what's installed.** The tracker question isn't asked again, so
  a public tracker can't have bloodhound added beside hound by pressing Enter. Extras
  already present aren't offered or re-required, and an app that already holds both
  tracker types is refused before any question. (#10803)
- **"The dashboard" as a home page option, and the default for a private tracker.**
  `/` redirects to usarrs's `dashboard.index`, which exists on every install the
  installer produces (it requires usarrs ^8.1, which ships the dashboard's panels). A public tracker still defaults to the
  splash page: its visitors are mostly guests, and the dashboard sits behind a login.
  Self-verification loads `/dashboard` too. When an admin account exists it does so as
  that admin, where a redirect counts as a failure, so every registered panel actually
  renders; without one it's a guest probe.
