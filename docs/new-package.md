# Adding a New Package

*Read this before creating a package in `packages/`. Everything here has already
cost someone a debugging session — most of it twice.*

Three packages have been added since the v2.0 lineup refactor: `squidink` and `parley`
(both 2026-08-20), and `taxonomy` (in progress, Build #95). The first two hit the same
gotchas in the same order. This doc exists so the third didn't have to, and the fourth
won't.

## The two that fail silently

These are the dangerous ones. Neither produces an error; both look like success.

### 1. `SPLIT_TOKEN` cannot create a repo under the org

It can push to an existing split repo. It **cannot create a new one**, and when the
repo is missing the split job **reports success anyway**.

Create the repo manually via the `lomsoftware` gh account *before* the first split,
then re-trigger with an empty commit:

```bash
gh repo create letterofmarque/<package> --public
git commit --allow-empty -m "chore: Trigger split for <package>"
git push
```

Verify by checking the split repo directly — never the Actions run list:

```bash
git ls-remote --tags lom:letterofmarque/<package>
```

`gh run list --limit N` returns the N most recent runs, which may be older ones if
nothing was triggered. That is indistinguishable from success.

### 2. A brand-new package needs a one-time manual Packagist submission

The webhook has nothing to fire on until the package exists on Packagist. Submit it
once, by hand, with `PACKAGIST_API_TOKEN` from Doppler and maintainer `lomsoftware`.

Job #10547 exists solely because this was missed on squidink.

## Scaffold

Copy an existing package rather than writing from scratch — `parley` is the closest
to a general template.

```
packages/<name>/
  composer.json
  config/<name>.php
  database/migrations/
  src/<Name>ServiceProvider.php
  tests/TestCase.php
  tests/Pest.php
  phpunit.xml
  phpstan.neon
  README.md
  CHANGELOG.md
```

**`composer.json`** — `php: ^8.3` (not `^8.4`; see the dependency floors in
[VERSIONING.md](../VERSIONING.md#dependencies-and-floors)), `illuminate/*: ^13.0`,
whichever `marque/*` packages you genuinely need. Dev: `orchestra/testbench: ^11.0`,
`pestphp/pest: ^4.7` (**not `^5.0`** — Pest 5 requires PHP 8.4 and would raise the
floor for every consumer), `mockery/mockery`, `nunomaduro/pao`. Plus
`extra.laravel.providers`, `extra.branch-alias`, `minimum-stability: dev` +
`prefer-stable`, and path repositories for sibling packages.

**`tests/TestCase.php`** — copy the four-engine `DB_CONNECTION` match from an existing
package verbatim: sqlite (with `foreign_key_constraints => true`), mysql, mariadb,
pgsql. Do not write a fresh one. The SQLite foreign-key line in particular was missing
from parley for the life of the package and nobody noticed (job #10548, and again
2026-09-04).

**`phpstan.neon`** — level 1, matching the others, and run via `composer stan` from
the repo root. Note it runs *per package*, not from the root like Pint: Larastan needs
each package's own `vendor/` to resolve models and facades.

### Two scaffold traps

**Testbench does not auto-discover package providers.** Register every sibling provider
your tests need explicitly in `TestCase::getPackageProviders()`. guise's suite broke on
a missing `IdServiceProvider` for exactly this reason.

**Do not create an empty `tests/Feature` directory.** An empty untracked directory named
in the testsuite config is what broke threepio's CI. Create it when you have a test to
put in it.

## Wiring

Four places, and missing any of them fails quietly rather than loudly:

| File | What to add |
|---|---|
| `.github/workflows/split.yml` | **Both** matrices — `split_branch` *and* the tag split |
| `.github/workflows/test-run.yml` | The package list |
| `.github/workflows/tests.yml` | The PHPStan matrix |
| `bin/release` | Nothing — it derives order from `composer.json` |

## Conventions already settled

Don't relitigate these per package; they were decided across squidink and parley and
apply suite-wide.

**Start at 1.0.0.** No 0.x history exists in the suite, and 0.x signals "do not rely on
this" to consumers. The one case worth reconsidering is a package whose *public
contract is a file format* rather than an API — Build #95 CP9 raises it for taxonomy,
where the YAML shape is the thing consumers write against.

**Optional inter-package dependencies use `class_exists` detection, not a hard
require.** guise renders comments when parley is installed and nothing when it isn't.
This keeps the dependent package at a MINOR bump and keeps the new package genuinely
optional.

**PHP-side seams compose; view-layer ones do not.** Blade resolves components at
*compile* time, so a `class_exists()` guard around `<x-ise::button>` still throws
wherever `ise` is absent. Own your markup, publish views, or take a hard dependency —
never attempt a conditional. This is Spec #83's central finding and it cost a
checkpoint to discover.

**Models declare explicit `$fillable`.** Never `$guarded = []`, never rely on
`Model::unguard()` — that's an application-level call a package cannot assume its
consumers have made. See [CONTRIBUTING.md](../CONTRIBUTING.md#mass-assignment); parley
shipped three models the wrong way until 2026-09-04.

**Per-record format columns are schema-critical.** Anything storing squidink text needs
a `body_format`-style column beside it. Adding one after data exists means a migration
*and* a backfill.

## Shipping

Ordinary release process from here: `bin/release <package> 1.0.0` handles dependency
ordering, the three-tags-per-push limit (GitHub silently drops workflow triggers above
that), and Packagist verification. `CHANGELOG.md` needs a `> summary` line under the
version heading or the release is refused.

Two doc updates that get forgotten:

- Root `README.md` — package table row, install snippet, features
- `docs/how.md` (maintainers only) — monorepo tree entry, and **recount the test table
  by running every suite** rather than incrementing the old number. Parley's checkpoint
  found it stale by 400 tests doing exactly that.

## Where the real detail lives

Cornerstone Builds carry more than this summary does, including what went wrong and
what was tried first:

- **Build #81** (`build-parley`) — CP2 scaffold, CP7 ship. The template.
- **Build #82** (`build-squidink`) — hit the same repo-creation gap first.
- **Build #95** (`taxonomy-engine`) — current, and the first to start from this doc.

Worth stating plainly, because it is the reason this file exists: **completed Builds are
a knowledge store, not just a record.** The parley build's checkpoint notes were the most
useful artefact in the project when scaffolding taxonomy, and they were nearly not
consulted. If you are about to do something structurally similar to past work, read the
Build before writing the plan.
