# Upgrading Marque

Everything you need to catch up: what the suite requires, what shipped in each
release, and the ordered path from whatever you're on to current.

Marque's packages version independently (see [VERSIONING.md](../VERSIONING.md) for
the per-package contract), but people upgrade the *suite*, not a package in
isolation — and "which packages need to move together, and why" isn't answerable
from version numbers alone. This page is that answer.

Looking for *everything* rather than the curated story? The root
[CHANGELOG.md](../CHANGELOG.md) is a flat, dated feed of every release of every
package, including the small ones that never warranted a write-up.

## Requirements

**PHP 8.3+ and Laravel 13+.** Composer enforces this — if your app doesn't meet it,
installing or upgrading any Marque package will refuse with a dependency conflict.

The Laravel floor has been 13 since [Marque 3.0](releases/3.0.md). The PHP floor
was 8.4 from 3.0 until Marque 5.1 **lowered** it to 8.3, which is Laravel 13's own
floor — nothing in Marque ever needed 8.4. Lowering a floor never breaks an existing
install, so there is nothing to do about this on an upgrade.

**If you already have any Marque package installed** and need to raise your Laravel
version too, upgrade Laravel and every Marque package you use **in one atomic
`composer require`**, not as two separate steps. See
[Marque 3.0's "What you need to do"](releases/3.0.md#what-you-need-to-do) for why —
the short version is that an old Marque package's own constraints block the new
Laravel version just as much as the new Marque version needs it, so there is no
working order to do it in two steps once anything is installed.

## Releases

Newest first. Each links to the full write-up.

| Release | Date | Summary | Who's affected |
|---|---|---|---|
| [6.0](releases/6.0.md) | 2026-09-11 | Marque gets an admin panel (`marque/skipper`); `marque/ise` renamed to `marque/deck`; packages now declare their own nav entries and admin screens | anyone using guise, disguise, usarrs or parley |
| [5.2](releases/5.2.md) | 2026-09-09 | `marque/taxonomy` added — declare your catalogue's structure in YAML instead of hardcoding it | nobody negatively; optional new package |
| [5.1](releases/5.1.md) | 2026-09-04 | PHP floor lowered to 8.3, matching Laravel 13's own requirement | nobody negatively; unblocks Laravel 13 apps on PHP 8.3 |
| [5.0](releases/5.0.md) | 2026-09-03 | Ratio becomes durable and auditable; per-torrent access control; API reads now require auth by default | everyone — especially bloodhound and cennad users |
| [4.3](releases/4.3.md) | 2026-09-02 | usarrs registers email verification + password confirmation routes, fixing a lockout for unverified users | usarrs users only, especially anyone using `verified`/`password.confirm` middleware or the admin panel |
| [4.2](releases/4.2.md) | 2026-09-01 | usarrs requires Fortify; adds off-by-default 2FA and passkeys; closes a Fortify route collision | usarrs users only |
| [4.1](releases/4.1.md) | 2026-08-26 | Tracker `passkey` renamed to `announce_key` (avoids collision with Laravel's own WebAuthn passkeys) | bloodhound, usarrs users only |
| [4.0](releases/4.0.md) | 2026-08-20 | `marque/id` renamed to `marque/ise` (now `marque/deck`); squidink and parley added | guise, usarrs, disguise users; anyone wanting rich text/discussion |
| [3.0](releases/3.0.md) | 2026-08-13 | PHP 8.4 / Laravel 13 now required | everyone |

## The path, in order

Oldest first — the reverse of the table above, because this is the order you *read*
rather than the order things shipped. Start at whichever row matches the version
you're on and read down. If you don't know your version, `composer show marque/trove`
(or whichever package you have) tells you.

Two kinds of document appear here:

- **Release** — plain-language "what changed and why". Read these to understand why
  a version exists.
- **Guide** — the technical, step-by-step checklist for one breaking change. Read
  these when a release points you at one for the full mechanics.

⚠️ **Guide titles name a PACKAGE version, not the suite version.** "usarrs v6" is
usarrs' own major, which shipped as part of *suite* 4.2. The suite column says which
release each guide belongs to.

| # | Kind | Doc | Suite | What it covers |
|---|---|---|---|---|
| 1 | Guide | [v1 → v2](upgrade-guide-v2.md) | 2.0 | The package-lineup refactor (4 packages → the current split) |
| 2 | Release | [Marque 3.0](releases/3.0.md) | 3.0 | PHP 8.4 / Laravel 13 floor raise — see [Requirements](#requirements) above for the current floor |
| 3 | Release | [Marque 4.0](releases/4.0.md) | 4.0 | `marque/id` → `marque/ise` rename; squidink and parley added |
| 4 | Release | [Marque 4.1](releases/4.1.md) | 4.1 | Tracker `passkey` renamed to `announce_key` |
| 5 | Guide | [bloodhound v4 / usarrs v5](upgrade-guide-bloodhound-v4-usarrs-v5.md) | 4.1 | Technical checklist for the `announce_key` rename |
| 6 | Release | [Marque 4.2](releases/4.2.md) | 4.2 | usarrs requires Fortify; adds 2FA/passkeys; closes a Fortify route collision |
| 7 | Guide | [usarrs v6](upgrade-guide-usarrs-v6.md) | 4.2 | Technical checklist for the Fortify changes |
| 8 | Release | [Marque 4.3](releases/4.3.md) | 4.3 | usarrs registers email verification + password confirmation routes (fixes a lockout) |
| 9 | Release | [Marque 5.0](releases/5.0.md) | 5.0 | Durable, auditable ratio accounting; per-torrent access control; API reads require auth by default |
| 10 | Release | [Marque 5.1](releases/5.1.md) | 5.1 | PHP floor lowered to 8.3 (nothing to do; unblocks PHP 8.3 apps) |
| 11 | Release | [Marque 5.2](releases/5.2.md) | 5.2 | `marque/taxonomy` added — declare your catalogue's structure in YAML (nothing to do; optional new package) |
| 12 | Guide | [ise → deck](upgrade-guide-ise-to-deck.md) | 6.0 | `marque/ise` renamed to `marque/deck`; namespace, view namespace, config and published-config changes |
| 13 | Release | [Marque 6.0](releases/6.0.md) | 6.0 | The admin panel arrives; the shell rename ripples through every frontend package |

## Currently at 2.x or earlier?

Read [3.0](releases/3.0.md) first — it's the floor raise everything else builds on —
then work down the table in order. Each release doc says plainly whether it applies
to the packages you actually use.

## What's next

There's no fixed release cadence for the suite as a whole — packages still tag
individually whenever a finished feature is ready (see
[VERSIONING.md](../VERSIONING.md)). A new entry appears here whenever a change is
significant or breaking enough that someone upgrading needs the story behind it, not
just the version diff.
