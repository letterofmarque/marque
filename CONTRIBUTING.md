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

- PHP 8.2+
- Composer
- Redis (for Bloodhound tests)

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

Tests use SQLite in-memory databases, so no database setup is needed. Bloodhound tests require a Redis connection.

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

## Code Style

Marque follows standard Laravel conventions:

- PSR-4 autoloading
- PSR-12 code style
- Type hints on method parameters and return types
- Dependency injection via Laravel's service container

## Reporting Issues

Use the [GitHub issue tracker](https://github.com/letterofmarque/marque/issues). Include:

- Which package is affected
- Steps to reproduce
- Expected vs actual behaviour
- PHP/Laravel version

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
