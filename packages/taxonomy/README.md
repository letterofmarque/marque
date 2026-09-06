# Marque Taxonomy

Declarative content-type engine for the [Marque](https://github.com/letterofmarque/marque)
tracker platform. A tracker declares its own shape in YAML — its hierarchy levels, its
facets, its optional entity fields — instead of having that shape hardcoded in schema.

```
  taxonomy-sport ─┐                              ┌─ upload form
  taxonomy-tv    ─┼─→  content-type definition ─→┼─ validation
  taxonomy-film  ─┤          (YAML)              ├─ query builder
  your own       ─┘                              └─ admin UI
```

> **Status: under construction.** This package is the engine being built over
> Build #95 against Spec #103. It currently ships a scaffold and no feature code.

## Why

Existing tracker software makes the tracker's *domain* a schema decision. Gazelle
carries `media`, `format`, `encoding`, `remaster_year` as columns on `torrents` — a
music shape wearing a config file. Run a multi-sport tracker at it (cricket has innings
and Test/ODI/T20; swimming has heats, semis and finals; cycling has stages) and each
sport is a schema change, a rewritten upload form and a rewritten search builder.

That is a fork, not a configuration.

Marque answers it with a file per domain. Generic tables underneath, runtime
definitions on top, and no admin-triggered migrations — because the failure mode of
generated migrations is someone renaming a level at 2am, a migration half-applying, and
a catalogue broken in a way they cannot undo. With runtime definitions the worst case
is "the definition did not load, nothing changed."

The engine ships **no domain vocabulary at all**. It does not know what a sport, a
season, a series or an episode is. Domain packages supply that.

## Installation

```bash
composer require marque/taxonomy
```

You generally will not install this directly — it arrives as a dependency of whichever
domain package you picked (`marque/taxonomy-sport`, `marque/taxonomy-tv`,
`marque/taxonomy-film`).

Publish the config if you want to change anything:

```bash
php artisan vendor:publish --tag=taxonomy-config
```

## The shape of a definition

```yaml
content_type: nfl_game
label: NFL Game
version: 1
levels:
  - season: { label: Season, type: year }
  - week:   { label: Week, type: integer, range: [1, 22] }
facets: [resolution, source, codec, broadcaster]
```

**Levels** are hierarchical and scoped to a content type. **Facets** are flat closed
vocabularies shared across content types, and may hold many values at once — a
Netflix-style rip with a dozen subtitle languages classifies without loss.

Scoping is what makes multi-domain work: `Week` belongs to *NFL Game*, `Stage` belongs
to *Cycling Stage*, and they cannot collide. A cycling uploader never sees `Week`.

Because levels are typed dimensions rather than merely nodes in a tree, filtering works
*across* the hierarchy and not only down it — "all 2006 games" and "all week 12 games"
are single queries spanning every league, which is precisely what a free-form category
tree cannot express.

## Requirements

- PHP 8.3+
- Laravel 13+
- `marque/trove` 4.0+

## Licence

MIT. See [LICENCE](../../LICENSE).
