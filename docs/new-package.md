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

**`tests/migrations/`** — if you depend on `trove`, you need a fixture creating the
`users` table. No package in the suite ships it (it is the host app's, reached via
`trove.user_model`), but trove's own migrations add columns to it, so without the
fixture every test in a brand-new package dies on `no such table: users`. Copy
parley's or taxonomy's, and load it *before* the package migrations in
`defineDatabaseMigrations()`.

Its `down()` matters as much as its `up()`: package migrations register first, so
rollback reaches `users` while tables referencing it still exist. SQLite shrugs;
MySQL and PostgreSQL refuse. Drop the dependants explicitly rather than relying on
`disableForeignKeyConstraints`, which Postgres ignores for `DROP TABLE`.

**Copying that `down()` verbatim is not enough — add your own tables to it.** The
fixture is what tears the environment down *between test files*, so by the time it
runs, `torrents` is about to go while **your** package's tables still point at it.
Parley's version drops `torrents` then `users` and knows nothing about yours.

This cost a debugging session on taxonomy (Build #95 CP4): the whole suite failed on
MySQL while **every test file passed in isolation** — the signature of a teardown
problem rather than a logic one. SQLite does not enforce foreign keys during that
drop and never noticed. List your tables deepest-first, above `torrents`:

```php
Schema::dropIfExists('yourpkg_assignments');   // deepest dependant first
Schema::dropIfExists('yourpkg_things');
Schema::dropIfExists('torrents');
Schema::dropIfExists('users');
```

If a suite passes file-by-file but fails as a whole on a real engine, look here
before looking at your code.

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
| `.github/workflows/split.yml` | The `split_branch` matrix. **Only that one** — `split_tag` parses the package name out of the tag (`<package>/v<version>`) and takes no matrix entry |
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

Three doc updates that get forgotten:

- Root `README.md` — package table row, install snippet, features
- `docs/how.md` (maintainers only) — monorepo tree entry, and **recount the test table
  by running every suite** rather than incrementing the old number. Parley's checkpoint
  found it stale by 400 tests doing exactly that.
- **`RELEASES.md` + `docs/releases/<n>.md` + `docs/upgrading.md`** — a new package is
  exactly the kind of event that page exists for. See below; this is the one that got
  missed on taxonomy.

### A new package needs a suite release entry

`RELEASES.md` is the "what do I need to know to catch up" page, written for people who
upgrade the *suite* rather than a package. **A brand-new package always warrants an
entry** — 4.0 got one for adding squidink and parley.

Taxonomy shipped without one (2026-09-09) and TYT noticed the gap before we did. The
cause is worth recording because it will recur: the Build's shipping checkpoint listed
the package README, root README, `how.md` and `CHANGELOG.md`, and that list got worked
as a checklist rather than as a prompt to ask *who reads what*. `RELEASES.md` is the
only one of these written for a consumer deciding whether they want the thing.

Three files, all of them:

1. `docs/releases/<n>.md` — the release doc itself. Follow
   [4.0](releases/4.0.md), which is the new-package precedent: what problem it solves
   in **tracker terms rather than engine terms**, what the config or usage actually
   looks like, who's affected, what they need to do.
2. [`RELEASES.md`](../RELEASES.md) — one row at the **top** of the Releases table.
3. [`docs/upgrading.md`](upgrading.md) — one row at the **bottom** of the ordered list.

**Pick the suite number by impact, not by momentum.** Suite numbers are not package
numbers and do not follow them. A purely additive optional package is a MINOR bump
(taxonomy was 5.2) — reserve a MAJOR for something that actually breaks an existing
install, the way 3.0 (floor raise) and 5.0 (auth defaults) did. Calling an additive
release 6.0 sends people hunting for migration steps that do not exist.

Check the claim before you pick: `git diff --stat <base> HEAD -- packages/ ':!packages/<new>'`
returning empty is what "purely additive" actually means.

**Expect the package-version confusion.** Packages version independently, so
`usarrs/v6.2.0` exists while the suite is on 5.x, and `docs/upgrading.md` carries rows
titled things like "Upgrade guide: usarrs v6". That is the exact reading that produced
"didn't we do a 6.0 release?" — resolving it is what `RELEASES.md` is *for*, so the
release doc should be unambiguous about which kind of number it is quoting.

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
