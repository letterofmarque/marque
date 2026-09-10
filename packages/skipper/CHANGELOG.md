# Changelog

All notable changes to `marque/skipper` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md).

## [1.0.0] — unreleased

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
