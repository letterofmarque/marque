# Marque Releases

Marque's packages version independently (see [VERSIONING.md](VERSIONING.md)
for the per-package contract), but people upgrade the *suite*, not a package
in isolation — and "which packages need to move together, and why" isn't
answerable from version numbers alone. This page is that answer.

Each release below bundles whatever package version bumps shipped together
for one real reason, in plain language: what's new, what changed, who's
actually affected, and exactly what to do about it. If you run Marque and
want to know "what do I need to know to catch up," start at the top and
read down to whatever you're currently on.

Looking for *everything* rather than the curated bundles? The root
[CHANGELOG.md](CHANGELOG.md) is a flat, dated feed of every release of every
package, including the small ones that never warranted an entry here.

## Requirements

**PHP 8.4+ and Laravel 13+**, as of [Marque 3.0](docs/releases/3.0.md). Composer
enforces this — if your app doesn't meet it, installing or upgrading any Marque
package will refuse with a dependency conflict.

If you already have Marque installed and need to raise your Laravel version too,
see [Marque 3.0's "What you need to do"](docs/releases/3.0.md#what-you-need-to-do) —
the short version is that Laravel and every Marque package need to move together in
one `composer require`, not as two separate steps.

For the full ordered list of every upgrade guide and release doc, see
[Upgrading Marque](docs/upgrading.md).

## Releases

| Release | Date | Summary | Who's affected |
|---|---|---|---|
| [5.0](docs/releases/5.0.md) | 2026-09-03 | Ratio becomes durable and auditable; per-torrent access control; API reads now require auth by default | everyone — especially bloodhound and cennad users |
| [4.3](docs/releases/4.3.md) | 2026-09-02 | usarrs registers email verification + password confirmation routes, fixing a lockout for unverified users | usarrs users only, especially anyone using `verified`/`password.confirm` middleware or the admin panel |
| [4.2](docs/releases/4.2.md) | 2026-09-01 | usarrs requires Fortify; adds off-by-default 2FA and passkeys; closes a Fortify route collision | usarrs users only |
| [4.1](docs/releases/4.1.md) | 2026-08-26 | Tracker `passkey` renamed to `announce_key` (avoids collision with Laravel's own WebAuthn passkeys) | bloodhound, usarrs users only |
| [4.0](docs/releases/4.0.md) | 2026-08-20 | `marque/id` renamed to `marque/ise`; squidink and parley added | guise, usarrs, disguise users; anyone wanting rich text/discussion |
| [3.0](docs/releases/3.0.md) | 2026-08-13 | PHP 8.4 / Laravel 13 now required | everyone |

## Currently at 2.x or earlier?

Read [3.0](docs/releases/3.0.md) first — it's the floor raise everything
else builds on — then work down the table in order. Each release doc says
plainly whether it applies to the packages you actually use.

## What's next

There's no fixed release cadence for the suite as a whole — packages still
tag individually whenever a finished feature is ready (see
[VERSIONING.md](VERSIONING.md)). This page gets a new entry whenever a
change is significant or breaking enough that someone upgrading needs to
know the story behind it, not just the version diff.
