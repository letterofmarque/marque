# Changelog

All notable changes to `marque/marque` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

- **"The dashboard" as a home page option, and the default for a private tracker.**
  `/` redirects to usarrs's `dashboard.index`, which exists on every install the
  installer produces. A public tracker still defaults to the splash page: its visitors are
  mostly guests, and the dashboard sits behind a login. Self-verification now also loads
  `/dashboard` as the new admin — a redirect counts as a failure — which renders every
  registered panel for real rather than only proving a route exists.
