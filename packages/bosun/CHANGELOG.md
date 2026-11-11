# Changelog

All notable changes to `marque/bosun` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md).

## [1.0.2] — 2026-11-11

> Accepts marque/usarrs 10.

### Changed

- **Requires `marque/usarrs` `^9.0|^10.0`.** usarrs 10 makes confirming it's you work for
  every member and hardens OAuth (see its changelog); bosun needs nothing new from it, and
  an app still on usarrs 9 keeps resolving.

## [1.0.1] — 2026-11-07

> The staff screen's member-being-changed can't be set from the client.

### Fixed

- **`Staff`'s `$changing` is `#[Locked]`** (#11135's sweep). It is set by `startChange()`.
  `applyChange()` already re-checked the policy, so a crafted update gained nothing, but the
  client has no business setting it.

## [1.0.0] — 2026-10-28

> New: staff management. A record of every staff action, when each staff member was last on, and the owner's Staff and Audit log screens.

### Added

- **Stores staff actions** recorded through trove's `StaffActionsInterface`: who, what, to
  which record, why and what changed. It fails open: a record that can't be stored never
  blocks the action, and is logged. Records are written in a savepoint inside the action's
  transaction. Integer user ids only: a record with a string or UUID actor is refused and
  logged, the same on every database. Kept for good by default; `bosun:prune` with `BOSUN_RETENTION_DAYS` trims
  them.
- **Staff presence**: a `web` middleware notes when each staff member was last on, at
  most every five minutes. Members are never tracked.
- **The Staff screen**: staff by role, with actions over 7, 30 and 90 days, last seen,
  and role changes with a required reason through usarrs' owner rules.
- **The Audit log screen**: every staff action, filterable by staff member, action,
  subject and date.
- Both screens register with trove's `AdminScreenRegistry` under Staff, for admins and
  the owner.
