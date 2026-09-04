# Contributing to Marque

Thanks for your interest in contributing to Marque. This guide covers the development setup, testing, and pull request process.

## Monorepo Structure

Marque is a monorepo. All packages live in `packages/` and are split to individual read-only repos on push to `main`:

```
packages/
├── trove/        → letterofmarque/trove
├── bloodhound/   → letterofmarque/bloodhound
├── cennad/       → letterofmarque/cennad
└── guise/        → letterofmarque/guise
```

**All pull requests should target this monorepo**, not the individual package repos.

## Development Setup

### Prerequisites

- PHP 8.3+ (8.4 works too — CI tests both)
- Composer
- Redis (for Bloodhound tests)
- Optionally MySQL, MariaDB and/or PostgreSQL — see [Testing](#testing)

**PHP 8.3 is the floor deliberately**, matching Laravel 13's own. One consequence worth
knowing before you touch `composer.json`: the test suite is pinned to **Pest 4**, because
Pest 5 requires PHP 8.4 and adopting it would raise the floor for every consumer — a MAJOR
across all eleven packages. See [VERSIONING.md](VERSIONING.md#dependencies-and-floors) for
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

## Testing

Each package has its own test suite using [Pest](https://pestphp.com/) and [Orchestra Testbench](https://packages.tools/testbench/).

Run tests for a specific package:

```bash
cd packages/trove
composer test
```

Or using Pest directly:

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

## Pull Request Process

1. **Fork the repo** and create a branch from `main`
2. **Make your changes** in the relevant package(s)
3. **Add tests** for new functionality
4. **Run the test suite** for any packages you've changed
5. **Open a PR** against `main` in the monorepo

### PR Guidelines

- Keep PRs focused - one feature or fix per PR
- If your change spans multiple packages, that's fine - submit it as one PR
- Include a clear description of what changed and why
- If it's a new feature, include usage examples in the description

### What Makes a Good PR

- Tests pass
- New functionality has test coverage
- Follows existing code patterns (Laravel conventions, service/contract pattern)
- Config options have sensible defaults and are documented

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

## Reporting Issues

Use the [GitHub issue tracker](https://github.com/letterofmarque/marque/issues). Include:

- Which package is affected
- Steps to reproduce
- Expected vs actual behaviour
- PHP/Laravel version

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
