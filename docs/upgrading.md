# Upgrading Marque

An index across every upgrade guide and release doc — nothing else ties these
together, so this page exists to answer "I'm on version X, what do I read?" without
fetching the whole repo tree and grepping for `upgrad|migrat|changelog`.

**Two kinds of doc, both listed below:**

- **Release docs** (`docs/releases/*.md`) — plain-language, "what changed and why,"
  linked from [`RELEASES.md`](../RELEASES.md). Read these to understand *why* a
  version exists.
- **Upgrade guides** (`docs/upgrade-guide-*.md`) — the technical, step-by-step
  checklist for a specific breaking change. Read these when a release doc points you
  to one for the full mechanics.

## The path, in order

| # | Doc | What it covers |
|---|---|---|
| 1 | [Upgrade guide: v1 → v2](upgrade-guide-v2.md) | The v2.0 package-lineup refactor (4 packages → the current split) |
| 2 | [Release: Marque 3.0](releases/3.0.md) | PHP 8.4 / Laravel 13 floor raise — see [Requirements](../RELEASES.md#requirements) for the current floor stated plainly |
| 3 | [Release: Marque 4.0](releases/4.0.md) | `marque/id` → `marque/ise` rename; squidink and parley added |
| 4 | [Release: Marque 4.1](releases/4.1.md) | Tracker `passkey` renamed to `announce_key` |
| 5 | [Upgrade guide: bloodhound v4 / usarrs v5](upgrade-guide-bloodhound-v4-usarrs-v5.md) | Technical checklist for the 4.1 rename |
| 6 | [Release: Marque 4.2](releases/4.2.md) | usarrs requires Fortify; adds 2FA/passkeys; closes a Fortify route collision |
| 7 | [Upgrade guide: usarrs v6](upgrade-guide-usarrs-v6.md) | Technical checklist for the 4.2 changes |
| 8 | [Release: Marque 4.3](releases/4.3.md) | usarrs registers email verification + password confirmation routes (fixes a lockout) |
| 9 | [Release: Marque 5.0](releases/5.0.md) | Durable, auditable ratio accounting; per-torrent access control; API reads require auth by default |
| 10 | [Release: Marque 5.1](releases/5.1.md) | PHP floor lowered to 8.3 (nothing to do; unblocks PHP 8.3 apps) |

If you're picking up an old install: start at whichever row matches the version
you're currently on, and read down to the bottom. If you don't know your version,
`composer show marque/trove` (or whichever package you have) tells you.

## Before you start any upgrade

Check [`RELEASES.md`'s Requirements section](../RELEASES.md#requirements) for the
current PHP/Laravel floor — every release from Marque 3.0 onward requires it, and
Composer will refuse to install otherwise.

**If you already have any Marque package installed**, upgrade Laravel and every
Marque package you use **in one atomic `composer require`**, not as two separate
steps. See [Marque 3.0](releases/3.0.md#what-you-need-to-do) for why — the short
version is that an old Marque package's own constraints block the new Laravel
version just as much as the new Marque version needs it, so there's no working
order to do it in two steps once anything is already installed.
