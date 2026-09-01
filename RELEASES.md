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

## Releases

| Release | Date | Summary | Who's affected |
|---|---|---|---|
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
