# Changelog

All notable changes to `marque/bosun` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md).

## [1.0.0] — 2026-10-28

> New: staff management. A record of every staff action, when each staff member was last on, and the owner's Staff and Audit log screens.

### Added

- **Stores staff actions** recorded through trove's `StaffActionsInterface`: who, what, to
  which record, why and what changed. It fails open: a record that can't be stored never
  blocks the action, and is logged. Records are written in a savepoint inside the action's
  transaction. Kept for good by default; `bosun:prune` with `BOSUN_RETENTION_DAYS` trims
  them.
- **Staff presence**: a `web` middleware notes when each staff member was last on, at
  most every five minutes. Members are never tracked.
- **The Staff screen**: staff by role, with actions over 7, 30 and 90 days, last seen,
  and role changes with a required reason through usarrs' owner rules.
- **The Audit log screen**: every staff action, filterable by staff member, action,
  subject and date.
- Both screens register with trove's `AdminScreenRegistry` under Staff, for admins and
  the owner.
