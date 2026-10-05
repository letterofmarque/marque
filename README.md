# Marque

A modular BitTorrent tracker platform for Laravel.

Built by [Letter Of Marque Software](https://lom.software).

## Packages

| Package | Description |
|---------|-------------|
| [marque/marque](packages/marque) | **Start here** — the installer that wires the rest into your app |
| [marque/trove](packages/trove) | Core models, services, contracts, and policies |
| [marque/bloodhound](packages/bloodhound) | Private BitTorrent tracker (announce/scrape) |
| [marque/cennad](packages/cennad) | REST API controllers and resources |
| [marque/guise](packages/guise) | Livewire web frontend (authenticated) |
| [marque/threepio](packages/threepio) | BitTorrent protocol primitives |
| [marque/hound](packages/hound) | Public BitTorrent tracker (no auth) |
| [marque/deck](packages/deck) | App layout shell (navigation, theming) |
| [marque/disguise](packages/disguise) | Public web frontend (browse without login) |
| [marque/usarrs](packages/usarrs) | Auth, user profiles, invites, admin |
| [marque/squidink](packages/squidink) | Format-agnostic text pipeline (Markdown, BBCode → HTML) |
| [marque/parley](packages/parley) | Polymorphic threaded discussion (torrent comments + forum) |
| [marque/taxonomy](packages/taxonomy) | Declarative content-type engine (YAML-defined levels + facets) |
| [marque/skipper](packages/skipper) | Admin panel — renders the admin screens packages register |

## Requirements

- PHP 8.3+
- Laravel 13+
- A database — PostgreSQL, MySQL, MariaDB or SQLite
- **A Redis server, plus `ext-redis` or `predis/predis`** — required by any deployment
  serving announces, i.e. anything with `bloodhound` or `hound` installed

Redis is not optional and not a cache you can swap out. Peer storage uses Redis sets,
hashes and atomic counters directly; pointing `CACHE_STORE` elsewhere does not degrade
the tracker, it fatals on the first announce. A catalogue-only or API-only install with
no tracker package genuinely does not need it. See
[`packages/threepio/README.md`](packages/threepio/README.md#redis-is-required-and-it-is-a-real-redis)
for the detail, including what happens if Redis restarts empty.

## Start here

```bash
composer require marque/marque
php artisan marque:install
```

Those two commands do the work, once the prerequisites below are in place (a mailer,
Redis, and built assets). `marque:install` asks what you are building — private
or public tracker, whether you want the API, forums, a taxonomy, an admin panel
— installs exactly those packages, and wires them into your app: routes, config,
migrations, your `User` model, the Tailwind sources your templates need, and a
front page. Then it checks the result actually responds before telling you it
worked.

It is safe to re-run, and that's how you add forums later. A second pass keeps the
tracker you have, offers only the extras you don't, and skips the wiring that's
already done. It still asks the site name and whether to create an admin.

**You will need a mailer configured first.** Registration, password reset and
invites all need one, and the installer sets up your admin account by emailing
you a link to choose a password — so without mail you would end up with an
account you could never sign in as. The installer refuses to start while
`MAIL_MAILER` is `log`, `array` or unset, or `smtp` with no host. It checks the
setting, not that mail actually arrives.

**You will need Redis running.** Every tracker keeps its peers in Redis, so the
installer refuses to continue if it can't reach the connection threepio uses.

**Build your assets before you run it, and again after** — `npm install && npm run
build`. Laravel doesn't ship compiled assets, and until they're built every page fails
on a missing Vite manifest, which fails the installer's own final check. It adds the
packages' Tailwind sources, so build again afterwards (or keep `npm run dev` running)
to see their styles.

### Wiring it up by hand

You do not have to use the installer. The packages install independently, and
these are the combinations that make a working site:

```bash
# Private tracker — login required, ratio tracked, full web UI + API
composer require marque/trove marque/bloodhound marque/guise marque/usarrs marque/cennad

# Public tracker — open announce, browse without an account
composer require marque/trove marque/hound marque/disguise

# Torrent catalogue, no tracker
composer require marque/trove marque/guise marque/usarrs
```

Each pulls in whatever it needs (`marque/threepio` for the BitTorrent protocol,
`marque/deck` for the shared UI shell) — you don't name those yourself. All three
verified installing cleanly as sets on 2026-09-10.

Going this way you also need to add the Marque traits to your `User` model, add
`@source` lines for the packages' views to `resources/css/app.css`, and give `/`
something to show. See [`packages/trove/README.md`](packages/trove/README.md) for
the `User` model, and note that the installer exists because missing one of those
steps produces an app that looks installed and is not.

**Do not install both `marque/bloodhound` and `marque/hound`.** hound registers
an open `announce` route with no key and no authentication, so a private tracker
that merely has it in `vendor/` carries a keyless announce endpoint beside its
authenticated one — and anyone who finds it can transfer without ever touching
ratio accounting.

## Installation

Adding packages individually, or already know what you need:

```bash
# Core (required)
composer require marque/trove

# Web frontend
composer require marque/guise

# REST API
composer require marque/cennad

# BitTorrent tracker
composer require marque/bloodhound

# Rich text for descriptions and posts
composer require marque/squidink

# Torrent comments and a lightweight forum
composer require marque/parley

# Declarative taxonomy engine (you declare the levels and facets in YAML)
composer require marque/taxonomy

# Admin panel — lists the admin screens your installed packages register
composer require marque/skipper
```

## Features

### Trove (Core)
- Role-based access control (user, uploader, moderator, admin)
- Torrent model with file parsing and info_hash extraction
- User ratio tracking (uploaded, downloaded, seedtime)
- Configurable policies for torrent management

### Bloodhound (Tracker)
- Redis-backed peer storage for high performance
- Version-based client whitelist/blacklist
- Anti-cheat detection (speed limits, swarm consistency, connection limits)
- Ratio tracking modes (full, off, seedtime) — planned, not yet enforced (#10732)
- Support for compact and dictionary peer formats

### Guise (Web UI)
- Livewire components for torrent browsing, viewing, uploading, editing
- Configurable layouts
- Dependency-free Blade UI components (from marque/deck), styled with Tailwind CSS

### SquidInk (Text)
- Markdown and BBCode in, HTML and plain text out, one document model between
- Site owner picks the input syntax; content records the parser that wrote it
- Schema-declared node vocabulary — unsupported input cannot become unexpected output
- Editor component whose toolbar is built from the active parser's own syntax
- Add your own parsers, renderers and shortcodes through the same API the built-ins use

### Cennad (API)
- RESTful torrent endpoints
- Token authentication through the app's `auth:api` guard (Sanctum, Passport or any compatible guard)
- Configurable routes and middleware

### Parley (Discussion)
- Torrent comments and a lightweight forum from one polymorphic model — attach discussion
  to any model with the `HasThreads` trait
- Arbitrary-depth nested replies, pin/lock/soft-delete moderation keyed off trove roles
- Forum behind a config toggle that genuinely removes its routes, not just its nav links
- Post bodies render through marque/squidink — no formatting or escaping of its own

### Taxonomy (Content types)
- A tracker's shape — its hierarchy levels, its facets — declared in YAML, not hardcoded
  in schema. Adding a domain is a file, not a fork
- Levels are scoped to a content type, so `Week` on NFL Game and `Week` on a TV type
  cannot collide, and neither uploader sees the other's fields
- Levels are typed dimensions rather than tree nodes, so "all 2006 games" and "all week 12
  games" are single queries spanning every league
- Definitions are parsed at runtime, never into migrations — a bad file leaves the tracker
  running on its previous definitions rather than half-applying a schema change
- A version bump with no declared migration path is refused, not warned about; a declared
  `rename_level` preserves every existing classification through a rename
- Classification data is never deleted by a definition edit — orphaned rows remain and are
  recoverable, enforced by foreign keys rather than by convention
- Cascading upload form and an admin screen that populates declared levels but deliberately
  cannot create or remove one
- Ships no domain vocabulary at all; your app declares its own levels and facets in YAML

## Configuration

Publish the config files:

```bash
php artisan vendor:publish --tag=trove-config
php artisan vendor:publish --tag=bloodhound-config
php artisan vendor:publish --tag=guise-config
php artisan vendor:publish --tag=cennad-config
php artisan vendor:publish --tag=parley-config
php artisan vendor:publish --tag=taxonomy-config
```

## Versioning

Packages follow [Semantic Versioning](https://semver.org) and are versioned
independently, so packages sit on different majors (usarrs on 8, threepio on 3) and that is normal.

Most people want a caret on the current major (`^8.2` for usarrs today), which is what `composer require` gives you by default: new
features and fixes automatically, never a breaking change. Minor releases are cut
frequently, so you should not need to track `dev-main` to get a finished feature.

See [VERSIONING.md](VERSIONING.md) for the full policy — what counts as patch, minor and
major, how the grey areas are decided, pre-releases, and the support window.

Package versions move independently, but if you want the plain-language story of what's
changed across the suite and what to do about it, see [docs/upgrading.md](docs/upgrading.md).

## Releasing

Packages are versioned independently, and releases are cut by the maintainers with
tooling that lives outside this repository (it needs push access to the tags, the split
workflow and Packagist).

What it does, for the record: runs the test suites first, sorts the release into
dependency order derived from the `composer.json` files, tags, pushes in batches of
three, waits for each split workflow, and verifies the versions reached Packagist.

To release by hand, tag as `<package>/v<version>` and push. The split workflow parses the
tag, splits only that package, and pushes the version tag to its sub-repo; Packagist
picks it up automatically. Two things to watch:

- **Push tags in batches of three or fewer.** GitHub suppresses workflow triggers when
  more than three tags arrive in a single push — the tags land, nothing splits, and
  Packagist is never notified. It fails silently.
- **Release in dependency order.** A package must be published before anything that
  requires it: `threepio` → `trove` and `deck` → everything else.

If a split is missed, re-trigger it from an existing tag:

```bash
gh workflow run 'Split Monorepo' -f tag=guise/v5.0.0
```

## Development

This is a monorepo. Each package in `packages/` is developed here and published to Packagist.

### Local Development

Add the path repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../marque/packages/*",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then require the packages with `@dev` stability:

```bash
composer require marque/trove:@dev marque/bloodhound:@dev
```

## License

MIT License - [Letter Of Marque Software](https://lom.software). See [LICENSE](LICENSE).
