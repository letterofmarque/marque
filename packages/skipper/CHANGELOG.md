# Changelog

All notable changes to `marque/skipper` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md).

## [1.1.0] — 2026-10-27

> Works with trove 5.0 as well as 4.x, and requires the trove 4.2 it was always built on.

### Changed

- **`marque/trove` is now `^4.2|^5.0`.** The widening is to 5.0: an owner passes every
  admin screen's role floor, because floors are "this role and above". The floor moves from
  `^4.0` to `^4.2`. Skipper has always used trove's `AdminScreenRegistry`, which arrived in
  4.2, so it never ran on 4.0 or 4.1. An install there stays on skipper 1.0.1.

## [1.0.1] — 2026-10-25

> The admin panel answers 403 to a member no registered screen admits, and a package's own admin route always answers its own path.

### Fixed

- **`/admin` answers 403 to a member no registered screen admits** (#10697). Each screen
  already refused them, but the panel's index gave any signed-in, verified member a 200
  and an empty panel at an admin URL. With nothing registered at all, the panel still
  renders and says so, for the operator to see.
- **When the screens open to someone exist but none has a route, the panel says so**,
  instead of claiming no admin screens are registered.
- **A screen whose package binds its own route is served only there** (#11108). usarrs'
  `admin/users` was the case. Three ways round it are closed:
  - Skipper registered its `admin/{screen}` catch-all when it booted, and Laravel answers
    with the first matching route. A package that loaded its routes later had its path
    answered by the catch-all, with skipper's `minimumRole` in place of the package's own
    middleware and checks. The catch-all is now registered last, once every provider has
    booted, and kept off any path already bound under the prefix.
  - The catch-all also served such a screen by its identifier (`/admin/usarrs-users`).
    Skipper now answers 404 for a screen whose declared path is bound by another route, by
    whatever URL reached it, checked per request.
  - Under a non-default `skipper.prefix`, skipper generated its own route for the screen
    (`/staff/users`) and the panel linked to it. It no longer does, and links to the
    package's route.
  The catch-all registered first also shadowed skipper's own generated routes where one
  screen's path segment matched another's identifier. "Its own route" means a route that
  answers GET at the screen's URL, found by asking the router, so a POST-only route doesn't
  count and a single-segment `path` is looked up under the prefix, not at the site root.
  A route a provider adds from its own `booted()` callback is too late to be kept clear of
  the catch-all, so under the default prefix a late route at a path that isn't a screen can
  be shadowed. A screen with a late route is still never served by skipper, and the panel
  links to that route. Verified live and under `route:cache` in a real Laravel app.
- **The README and config described `prefix` and `path` wrongly.** A screen's `path` loses
  its first segment and is served under `skipper.prefix`, so the prefix moves the screens
  too, not just the panel index.

## [1.0.0] — 2026-09-10

> The admin panel — renders the admin screens packages register, and depends on none of them.

### Added

- **The panel**, at `/admin`, named `admin.index`. Marque had no admin panel at
  all before this: the entire admin surface across twelve packages was usarrs'
  two user screens and taxonomy's admin component, neither reachable from
  anywhere. deck's navigation linked to an `admin.index` that nothing
  registered.

- **Screens come from `marque/trove`'s `AdminScreenRegistry`.** Packages declare
  their own; skipper names none of them. Nothing depends on skipper, so a
  package registering a screen behaves identically whether or not the panel is
  installed — which is what makes third-party admin screens possible.

- **Grouped, ordered listing.** Screens group under their declared `group`
  (ungrouped ones fall under `skipper.default_group`), ordered by `position`
  then label, with groups themselves sorted so registration order does not
  decide the layout.

- **Role filtering on the listing**, reading each screen's `minimumRole` against
  trove's `Role` ranking. Enforcement at route resolution — the half that stops
  a moderator reaching an admin screen by typing its URL — arrives with screen
  routing.

- **Config**: `middleware`, `prefix`, `title`, `layout`, `default_group`.
  Publishable via `--tag=skipper-config`; views via `--tag=skipper-views`.

### Notes

- An empty panel is a normal state, not an error — a fresh install has skipper
  and nothing else, and says so rather than rendering a blank page.
- A screen whose route is not bound is skipped rather than taking the panel down
  with a `RouteNotFoundException`. One package's mistake should cost that package
  its tile, not everyone else their admin access.
