# marque/bosun

Staff management for the [Marque](https://github.com/letterofmarque/marque) tracker
platform: a record of what every staff member did, when each was last on, and the owner's
Staff and Audit log screens.

## Why

Most trackers can't answer the questions an owner asks of their staff team. Who are the
moderators? Which of them actually moderates? Who banned this member, and why? Who
demoted that admin? Bans, role changes, torrent edits and forum moderation leave no
trace, and nothing records when a staff member was last on the site.

Every Marque package records its staff actions through `marque/trove`'s
`StaffActionsInterface`: a ban, a role change, a torrent edited by someone who isn't its
uploader, a thread locked, a term added to the catalogue. Without bosun those records go
nowhere. Install bosun and they're kept, from that day on.

## Installation

```bash
composer require marque/bosun
php artisan migrate
```

bosun requires `marque/trove` ^5.0, `marque/usarrs` ^9.0 and `marque/deck`. Its screens
register with trove's `AdminScreenRegistry`, so
[`marque/skipper`](https://github.com/letterofmarque/skipper), the admin panel, lists them
under **Staff**, visible to admins and the owner. Without skipper, mount
`<livewire:bosun-staff />` and `<livewire:bosun-audit-log />` on routes of your own. Both
refuse anyone below Admin themselves.

**Make someone the owner first.** Only an owner grants or removes Admin, so a tracker with
no owner can't make another admin:

```bash
php artisan usarrs:owner you@example.com
```

## What's recorded

Whatever the packages record. As of Marque 11.0 that is:

| Package | Actions |
|---|---|
| usarrs | `usarrs.user.banned`, `unbanned`, `role_changed`; `usarrs.invite.revoked` |
| trove (through guise, disguise and cennad) | `trove.torrent.edited`, `deleted` |
| bloodhound | `bloodhound.exemption.granted`, `removed`; `bloodhound.seedbox.approved`, `revoked` |
| parley | `parley.thread.pinned`, `unpinned`, `locked`, `unlocked`, `edited`, `deleted`; `parley.post.edited`, `deleted` |
| taxonomy | `taxonomy.term.added`, `renamed`, `deleted`; `taxonomy.facet_value.added`, `deleted` |

Each record keeps who did it, what, to which record, the reason when one was given, and
what changed (the fields that moved, before and after). A member editing their own
torrent or post isn't a staff action and isn't recorded. Automatic enforcement (a ratio
watch starting, a hit-and-run strike) isn't either.

Records are kept for good by default. To trim them, set `BOSUN_RETENTION_DAYS` and
schedule the prune:

```php
Schedule::command('bosun:prune')->daily();
```

### A record that can't be stored never blocks the action

bosun fails open. If storing a record fails, the ban or role change still happens, the
error is logged (`bosun: a staff action could not be recorded`) and reported, and the gap
shows in the log. Records are written inside the staff action's own transaction, in a
savepoint, so a failed write doesn't take the caller's transaction with it on PostgreSQL.

bosun stores actor and user ids as integers. An app with string or UUID user ids will
see each record fail, and log, as above.

## The Staff screen

The staff by role (owner, admin, moderator), with each member's staff actions over the
last 7, 30 and 90 days, when they were last on the site, and a link to their actions in
the Audit log.

Changing a role takes a reason, and goes through usarrs, so the owner rules apply: admins
manage moderators and uploaders, only the owner grants or removes Admin and Owner, nobody
changes their own role, and the last owner stays. The screen offers only the roles you
may give. The change is recorded with its reason. A member not yet on the staff is added
by their address.

## The Audit log

Every staff action, newest first, filterable by staff member, action, subject and date.

## Staff presence

A middleware on the `web` group notes when each staff member was last on the site, at most
once every five minutes. Only staff are stamped: a member's activity is never tracked.

## Configuration

```bash
php artisan vendor:publish --tag=bosun-config
```

| Key | Default | |
|---|---|---|
| `retention_days` | `null` (`BOSUN_RETENTION_DAYS`) | Days `bosun:prune` keeps; null keeps everything |
| `presence.enabled` | `true` (`BOSUN_PRESENCE`) | Stamp staff presence |
| `presence.interval_minutes` | `5` | How often a staff member is stamped, at most |
| `staff_role` | `moderator` | The lowest role counted as staff |

Views publish with `--tag=bosun-views`.

## Requirements

- PHP 8.3+
- Laravel 13+
- `marque/trove` 5.0+
- `marque/usarrs` 9.0+

## License

MIT
