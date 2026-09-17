# Contributing to Marque

Thanks for your interest in contributing to Marque. This guide covers how to report
something, how to get a patch in, and the development setup behind both.

## How to contribute

**Open an issue on the monorepo.** That is the front door for everything — bug reports,
feature requests, questions about whether Marque can already do the thing you want:

**https://github.com/letterofmarque/marque/issues**

Pull requests on this repo are limited to collaborators, and the individual package repos
have issues and pull requests turned off entirely. That is not "we don't want your help" —
[there is a route in for a patch you already have](#i-already-have-a-patch), and it still
works. It is that starting from a description of the *problem* works better here than
starting from a diff, for three reasons:

1. **Most of what arrives will not be code.** Tracker admins are skilled at running
   trackers — sysadmin, config, tuning, moderation. "How do I make it do X" and "can it do
   Y" are the common cases, and you cannot open a pull request for a question.
2. **Reviewing a patch here is expensive.** A green SQLite run
   [proves less than it looks like](#running-against-a-real-database), real-engine runs
   take minutes, and two packages cannot share a test database. Evaluating an external
   patch costs a four-engine review whatever its quality.
3. **A patch arrives with the solution already chosen.** Which packages you have
   installed, what your announce traffic looks like, which client, what scale — that
   context is the part only you have, and a diff compresses it away.

### What makes a good issue

No mandatory template. A long required form suppresses reports, and a thin report about a
real problem beats a polished one about an imagined one. Include what you can:

- Which package(s), and which others are installed alongside
- Marque version, plus PHP and Laravel versions
- Database engine — the suite supports four and they genuinely diverge
- Expected vs actual behaviour
- For tracker behaviour: the client, and roughly what the announce traffic looks like
- A minimal reproducer if you have one — helpful, not required

### Three outcomes, all of them useful

An issue is worth filing even when the answer is "no code change":

1. **Already possible** — you get an answer, and it just found a documentation gap.
2. **Possible once we expose a seam** — it shapes where the next extension point goes.
3. **Belongs in your own package** — you get pointed at the extension point that lets you
   build it without waiting for us.

### Issues prepared with an AI agent

Welcome, and they can be excellent. Point your agent at this guide.

**But verify the thing you are reporting actually happened.** Agent-generated analysis is
valuable when it describes something observed and misleading when it describes something
plausible. A confident, detailed, entirely fictional bug report costs more to disprove
than a one-line real one costs to fix. Mass-generated speculative issues are not welcome.

## I already have a patch

Good — send it. Pull requests being collaborators-only removes some GitHub automation, not
the ability to contribute code.

A pull request is a GitHub feature wrapped around a plain git operation. Git itself has no
concept of one; the underlying command is `git pull <url> <branch>`, which is what the
feature was named after. Fork, branch, push to your own remote, and post the ref in an
issue:

```
git remote add contributor https://github.com/you/marque.git
git fetch contributor
git checkout contributor/your-branch
```

Linux, Git and PostgreSQL have all worked this way for decades.

Contribute regularly and land good patches and you get made a collaborator, at which point
you can open pull requests directly. Collaborator status is not push access to `main` —
that is branch protection, a separate control.

### The bar a branch needs to clear

Whether it arrives as a posted ref or a collaborator's pull request:

- One feature or fix per branch, focused
- Tests for new functionality
- The test suite passes for every package you touched
- Follows existing patterns — Laravel conventions, the service/contract pattern
- Config options have sensible defaults and are documented
- `composer lint` run before you send it — releases are refused on a style violation
- A clear description of what changed and why, with usage examples for a new feature

A change spanning multiple packages is fine as one branch.

## Monorepo Structure

Marque is a monorepo. All fourteen packages live in `packages/` and are split to
individual read-only repos on push to `main`:

```
packages/
├── trove/        → letterofmarque/trove
├── threepio/     → letterofmarque/threepio
├── bloodhound/   → letterofmarque/bloodhound
├── hound/        → letterofmarque/hound
├── usarrs/       → letterofmarque/usarrs
├── deck/         → letterofmarque/deck
├── guise/        → letterofmarque/guise
├── disguise/     → letterofmarque/disguise
├── skipper/      → letterofmarque/skipper
├── cennad/       → letterofmarque/cennad
├── squidink/     → letterofmarque/squidink
├── parley/       → letterofmarque/parley
├── taxonomy/     → letterofmarque/taxonomy
└── marque/       → letterofmarque/installer
```

Those are generated mirrors — issues and pull requests are turned off on all of them.
**Everything targets this monorepo.**

## Development Setup

### Prerequisites

- PHP 8.3+ (8.4 works too — CI tests both)
- Composer
- Redis (for Bloodhound tests)
- Optionally MySQL, MariaDB and/or PostgreSQL — see [Testing](#testing)

**PHP 8.3 is the floor deliberately**, matching Laravel 13's own. One consequence worth
knowing before you touch `composer.json`: the test suite is pinned to **Pest 4**, because
Pest 5 requires PHP 8.4 and adopting it would raise the floor for every consumer — a MAJOR
across all fourteen packages. See [VERSIONING.md](VERSIONING.md#dependencies-and-floors) for
the full reasoning and the transitive traps that go with it.

### Clone and Install

```bash
git clone https://github.com/letterofmarque/marque.git
cd marque
```

Each package manages its own dependencies. Install and test from within each package directory:

```bash
cd packages/trove
composer install
```

Packages reference each other via path repositories, so local changes are reflected immediately.

## Adding a new package

Read **[docs/new-package.md](docs/new-package.md)** first. It carries the conventions
(starting version, optional-dependency detection, mass assignment, the four-engine test
harness) and, more usefully, the handful of steps that fail *silently* rather than
loudly — two of which have caught out every new package added so far.

## Testing

Each package has its own test suite using [Pest](https://pestphp.com/) and [Orchestra Testbench](https://packages.tools/testbench/).

Run tests for a specific package:

```bash
cd packages/trove
composer test
```

Output is a single line of JSON rather than a line per test — that's
[PAO](https://github.com/nunomaduro/pao), installed as a dev dependency in every package:

```json
{"tool":"pest","result":"passed","tests":61,"passed":61,"assertions":121,"duration_ms":967}
```

Failures include the test name, file, line and message, and the exit code is non-zero as
usual. If you'd rather see the full reporter while working on a test, run Pest directly:

```bash
cd packages/bloodhound
./vendor/bin/pest
```

Tests default to SQLite in-memory, so no database setup is needed to get started.
Bloodhound tests require a Redis connection.

### Running against a real database

**A green SQLite run proves less than it looks like.** SQLite ignores `->after()` column
positioning, tolerates an abandoned transaction, and defaults foreign keys off (Marque
turns them on explicitly). The first real-engine run of this suite found eight test-only
bugs that had been invisible for the life of the project.

Marque is DB-agnostic, and the suite can be pointed at any of the four supported engines:

```bash
DB_CONNECTION=mysql   composer test
DB_CONNECTION=pgsql   composer test
DB_CONNECTION=mariadb DB_PORT=3307 composer test
```

Defaults to database `marque_test`, user `marque`/`marque` on the engine's standard port;
override with `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`.

MariaDB is a distinct engine, not a MySQL alias — Laravel ships its own `MariaDbConnection`
and grammar, and the two diverge on JSON storage, index length limits and `RETURNING`. It
cannot be installed alongside MySQL from apt (both packages claim `virtual-mysql-server`),
so a container on a spare port is the practical way to run both.

Two things to know when running real engines:

- **They are much slower.** Real DDL runs per test, so a large package takes minutes rather
  than seconds.
- **Never run two packages against the same database at once.** They share the same
  database name and will destroy each other's schema mid-run, producing a flood of failures
  that have nothing to do with your change.

A handful of tests are SQLite-specific by nature (`EXPLAIN QUERY PLAN` index probes, a
`PRAGMA foreign_keys` assertion). They skip on other engines rather than failing.

### Running All Tests

From the repo root, run each package's tests:

```bash
for pkg in packages/*/; do
    echo "=== Testing $pkg ==="
    (cd "$pkg" && composer install --quiet && composer test)
done
```

## Static Analysis

PHPStan (with Larastan) runs at level 1 across every package:

```bash
composer stan                                          # all packages
cd packages/trove && ../../vendor/bin/phpstan analyse   # just one
```

Unlike Pint, it runs **per package** — Larastan needs each package's own `vendor/` to
resolve models and facades. The binary is shared from the repo root, so run
`composer install` at the root once before using it.

Two rules if you hit an error:

- **Fix the cause, not the symptom.** Do not add `@phpstan-ignore` comments, baseline
  entries, `assert()`, inline `@var`, or type casts to silence something.
- **Model properties need `@property` docblocks.** PHPStan cannot know a column exists
  without a database to inspect. Add the annotation to the model — consumers get IDE
  autocompletion out of it too.

## Mass assignment

Every Marque model declares an explicit `$fillable`. **Never use `$guarded = []`, and never
rely on `Model::unguard()`.**

That is a rule for packages specifically. `unguard()` is an application-level call, and a
package cannot assume its consumers have made it — or that they have not. A model shipped
with `$guarded = []` is fully mass-assignable in every app that installs it, including
columns that only privileged code should ever write. `parley`'s models shipped that way
until 2026-09-04; nothing had gone wrong, but the exposure was real and free to remove.

`packages/parley/tests/Unit/MassAssignmentTest.php` enforces this. Copy it into any package
that gains models.

## Code Style

Marque follows standard Laravel conventions:

- PSR-4 autoloading
- PSR-12 code style
- `declare(strict_types=1)` in every PHP file
- Type hints on method parameters and return types
- Dependency injection via Laravel's service container

Style is enforced by [Pint](https://laravel.com/docs/pint), installed once at the repo
root and run across every package at once:

```bash
composer lint         # fix
composer lint:test    # check only
```

Run `composer lint` before opening a pull request — releases are refused on a style
violation.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
