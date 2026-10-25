# Changelog

All notable changes to `marque/taxonomy` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] — 2026-10-25

> The upload form now checks who is classifying: only the torrent's uploader or a moderator can, where before anyone who reached the form could.

### Fixed

- **`ClassifierForm` authorises against the torrent (#11109).** It asked nobody before:
  any member who could reach a page embedding the form, for a torrent that wasn't theirs,
  could reclassify it, unless the app's own route happened to check. It now requires
  trove's `TorrentPolicy::update` (the uploader, or a moderator or above) on mount and on
  save, and answers 403 otherwise, guests included. The `Classifier` service is unchanged:
  direct callers still decide who may classify.

## [1.1.0] — 2026-09-11

> The admin screen is now reachable — it registers itself, so installing the panel lists it automatically.

### Added

- **Registers `TaxonomyAdmin` with trove's `AdminScreenRegistry`.** The component
  shipped in 1.0.0 with no route and no navigation entry — it existed and nothing
  could reach it. Installing
  [`marque/skipper`](https://github.com/letterofmarque/skipper) now puts
  **Taxonomy** under *Content* at `/admin/taxonomy`, visible to admins.

  The declared floor is `Role::Admin`, matching what `TaxonomyAdmin` already
  enforces on mount and on every write.

  Registration sits inside the existing `class_exists(Livewire::class)` guard:
  the screen *is* the Livewire component, so an API-only install that classifies
  without a frontend stack does not advertise a screen it cannot render.

- taxonomy does **not** require or suggest skipper. With no panel installed the
  registration is never read, and you can still mount
  `<livewire:taxonomy-admin />` on a route of your own.

## [1.0.0] - 2026-09-09

> A tracker declares its own taxonomy in YAML instead of having it hardcoded in schema — adding a domain is a file, not a fork.
>
> Hierarchy levels, shared facet vocabularies, and declared migration paths between
> versions of a definition. The engine ships no domain vocabulary of its own.

### Added

- **Content types declared in YAML.** Ordered levels (`string`, `integer`, `year`, with
  optional ranges) and shared facet vocabularies. No migration, no deploy, no PHP.
- **Levels are typed dimensions scoped to a content type**, so `week` on one type and
  `week` on another are independent, and "all week 12 games" is a single query across every
  league regardless of what depth `week` sits at in each.
- **Generic schema** — terms (materialised path), facets and their values, many-to-many
  assignments, and per-torrent classification carrying a nullable grouping key.
- **Strict validation**, with `marque:taxonomy:validate` as a deploy gate. A definition
  loads completely or not at all: one bad file leaves the tracker on its previous
  definitions rather than a half-applied taxonomy.
- **Declared migration paths.** A version bump with no migration path is refused, not
  warned about. `rename_level` preserves every existing classification through a rename
  that a naive loader would turn into mass orphaning.
- **`marque:taxonomy:upgrade`**, which reports what changes and how many torrents are
  affected before asking. `composer update` never reshapes a live catalogue.
- **Classification data is never deleted by a definition edit** — orphaned rows remain and
  are recoverable, enforced by foreign keys rather than by convention.
- **Cascading upload form** (`<livewire:taxonomy-classifier-form />`) — pick a content
  type, then walk its hierarchy one level at a time. A cycling uploader never sees `Week`.
- **Admin screen** (`<livewire:taxonomy-admin />`) for populating declared levels and
  curating vocabularies. Deliberately offers no way to create or remove a level.
- App definitions override package-shipped ones of the same name, and a package update
  never silently replaces an override.

### Notes

- The engine ships **no domain vocabulary**. A test tokenises the source and greps it to
  keep it that way.
- `livewire/livewire` is a suggestion rather than a requirement: an API-only install
  classifies through the service without a frontend stack.
- Runs on SQLite, MySQL, MariaDB and PostgreSQL, exercised against all four.
