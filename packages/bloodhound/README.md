# Marque Bloodhound

BitTorrent tracker for the [Marque](https://github.com/letterofmarque/marque) platform. Handles announce/scrape with Redis-backed peer storage, client validation, anti-cheat, and a ledger that every member's ratio and seedtime is summed from.

## Starting from scratch?

Bloodhound is the tracker half only — no frontend, no auth, no upload UI. For a
complete private tracker, install the set:

```bash
composer require marque/trove marque/bloodhound marque/guise marque/usarrs marque/cennad
```

That resolves `marque/threepio` and `marque/deck` for you.

Prefer a public, no-login tracker? Use [marque/hound](https://packagist.org/packages/marque/hound)
and [marque/disguise](https://packagist.org/packages/marque/disguise) instead of
bloodhound and guise.

## Requirements

- **Redis, configured to keep what it holds**: `appendonly yes` and
  `maxmemory-policy noeviction`, on a database of its own. See below; in production
  bloodhound refuses announces until it is.
- PHP 8.3+
- Laravel 13+
- [marque/trove](https://packagist.org/packages/marque/trove)
- MySQL, MariaDB, PostgreSQL, or SQLite 3.33 or later.

### Redis

Bloodhound keeps every peer's last reported counters in Redis and credits each announce
against them. Redis is therefore not a cache here: it holds the baseline your members'
ratio and seedtime are measured from. A Redis that restarts empty, or evicts a key when
memory runs out, silently costs whichever members' records it dropped.

So run the tracker's Redis with:

```conf
appendonly yes
appendfsync everysec
maxmemory-policy noeviction
```

and give it a database (or an instance) of its own: point `THREEPIO_REDIS_CONNECTION` at a
connection in `config/database.php` that the cache, queue and sessions don't use. A
`FLUSHDB` aimed at your cache would otherwise take the swarm with it. On a managed service,
set the same in its parameter group.

Then check it:

```bash
php artisan bloodhound:check-redis
```

It reads `aof_enabled` from `INFO persistence` and `maxmemory_policy` from `INFO memory`,
and prints what it found, what is needed and the lines to set. It exits 1 when the check
fails. It reads `INFO` rather than `CONFIG GET` because managed services generally allow
`INFO` where they disable or rename `CONFIG`. It also warns, without failing, when the
tracker's Redis connection points at the same host, port and database as the cache, queue
or session store.

**In production, announces are refused until the check passes.** Each one is answered
`Tracker storage is not configured for durability; the operator has been notified`, and
the failure is logged at `critical`. No ledger row is written for these, since the refusal
is the tracker's, not the member's. The verdict is cached in your application cache for
five minutes, so a fix takes effect within five minutes, and the log is written once per
window. This is deliberately a hard stop: a tracker that credits ratio against a store
that can forget is worse than one that is briefly down, and putting it right is a
two-line change. In any other environment (`APP_ENV` other than `production`) a warning is
logged instead and announces go ahead, so development machines and CI need nothing.

`bloodhound:aggregate-ledger`, scheduled every minute, runs the same check and logs a
failure (`critical` in production, `warning` elsewhere), so a tracker nobody is announcing
to still reports it.

**If your provider makes the data durable another way** (replication, snapshots) and
offers no AOF, set `BLOODHOUND_REDIS_DURABILITY=attested`. That satisfies the persistence
check. It covers the eviction policy only when the server refuses `INFO` altogether: when
`INFO` reports a policy other than `noeviction`, the check fails whatever you attest,
because every provider lets you set the policy and an evicted peer record is a lost
baseline.

`marque:install` runs the same check when it installs a private tracker, and stops before
installing anything until it passes. `BLOODHOUND_REDIS_DURABILITY=attested` in `.env`
covers persistence there too; the eviction policy still has to be `noeviction`.

If Redis is lost anyway, bloodhound notices and reads baselines from the ledger for a
while: see [Recovery mode](#recovery-mode). The audit then names every peer whose session
the loss cut.

You also need `ext-redis` or `predis/predis`; see
[threepio's README](../threepio/README.md#redis-is-required-and-it-is-a-real-redis).

### Database

Bloodhound runs on MySQL, MariaDB, PostgreSQL and SQLite. The aggregator's batched
writes are spelled for those four (`UPDATE ... JOIN` on MySQL and MariaDB,
`UPDATE ... FROM` on the others) and refuse any other driver. SQLite needs 3.33 or later
for `UPDATE ... FROM`; below it you get a SQL syntax error from the aggregator, not a
clear refusal, so check `sqlite3 --version` before upgrading. The suite is tested on MySQL 8.0, MariaDB 11,
PostgreSQL 17 and SQLite.

## Installation

```bash
composer require marque/bloodhound
```

Publish the config and run migrations:

```bash
php artisan vendor:publish --tag=bloodhound-config
php artisan migrate
```

Bloodhound schedules its own commands (see [Commands](#commands)), so it needs
[Laravel's scheduler running](https://laravel.com/docs/scheduling#running-the-scheduler).

## How It Works

Bloodhound registers two endpoints:

| Endpoint | Purpose |
|----------|---------|
| `GET /announce/{announce_key}` | Peer announces (start, stop, complete) |
| `GET /scrape/{announce_key?}` | Swarm statistics |

Those are the defaults — **the URL shape is configurable**, which matters if you are
migrating an existing tracker. See [Announce URL shape](#announce-url-shape).

Users authenticate via their announce key. Keys live in bloodhound's own `announce_keys`
table: `HasTrackerStats` issues one when a user is created, and other code reads or
regenerates it through trove's `TrackerStatsInterface`, which bloodhound binds — see
[trove's README](../trove/README.md).

**If your `User` implements `MustVerifyEmail`, the key waits for a verified
address.** An unverified sign-up gets no key, and bloodhound issues one when
Laravel's `Verified` event fires. Without this, someone could sign up with
another person's address and announce before anyone proved the inbox. Taking
the key back afterwards would mean rotating it, and every rotation makes the
user re-download their `.torrent` files. A user who already has a key keeps it
when their address is verified. Users created already verified, and apps that
don't verify addresses, get a key at creation as before. The legacy `users.announce_key` column is deprecated
and never read. Peer data is stored in Redis; the announce path makes one query to resolve
the user.

### Announce URL shape

**If you are migrating an existing tracker, read this first.** Every .torrent file already
in circulation announces to your old URL, and those files are on strangers' disks — you
cannot reissue them. So bloodhound lets you keep serving the URL they expect:

```php
'routes' => [
    'announce_path' => 'announce',     // e.g. 'announce.php'
    'scrape_path'   => 'scrape',
    'key_source'    => 'path',         // 'path' | 'query'
    'key_parameter' => 'announce_key', // e.g. 'passkey'
    'key_pattern'   => '[0-9a-zA-Z]{32}',
],
```

Coming from TBDev, Gazelle, or one of their many forks — where the key is a query
parameter — that is:

```env
BLOODHOUND_ANNOUNCE_PATH=announce.php
BLOODHOUND_SCRAPE_PATH=scrape.php
BLOODHOUND_KEY_SOURCE=query
BLOODHOUND_KEY_PARAMETER=passkey
```

...giving you `GET /announce.php?passkey=<key>`.

This is **configurable routing, not a legacy compatibility mode.** There are no
per-tracker special cases in here and there should never be any — the config says "your
URLs, your choice" and stays neutral about where you came from.

Four things worth knowing:

**Change scrape too.** Changing announce and leaving scrape at the default is a
half-migration, and clients will scrape the wrong URL.

**Route names never change.** They are `tracker.announce` and `tracker.scrape` whatever
you configure, so `route('tracker.announce', ...)` keeps working.

**`key_pattern` is a security surface.** It gates what reaches a database lookup. The
default matches what bloodhound itself mints — `Str::random(32)`, i.e. 32 alphanumeric
characters. Keep it as tight as your existing keys allow; widening it is your risk to
take. Don't anchor it yourself, and don't add delimiters — it is used directly as a
Laravel route constraint and anchored for you when matching.

**`key_source` changes where errors come from.** With the key in the path, a malformed
key is a **404 from the router** and no application code runs. As a query parameter every
request reaches the controller, which answers a malformed key with a bencoded
`failure reason` at **HTTP 200** — the same shape as every other tracker error, because a
4xx reads to a BitTorrent client as an unreachable tracker rather than a message worth
showing the user. On announce a missing key is refused the same way. Scrape accepts a
missing key, since a public scrape is legitimate, and refuses only a malformed one.
Trackers get probed constantly; know which of the two you are running.

#### An nginx rewrite is the right fix for an outage, not for a migration

If your announce URLs are 404ing right now, a rewrite at the web server is the fastest
way out and you should do it. As the permanent answer it is weak: it puts a routing
concern in deployment config where the application cannot see it, it is easy to lose on a
server rebuild, it cannot be covered by this package's tests, and it does nothing for
anyone on a host where they do not control the web server.

#### Before you build "unknown key → import from the old tracker"

A migration often wants to auto-create an account the first time a legacy key announces.
That feature belongs in your application, not here — but note this before you build it:
**bloodhound mints its own 32-character alphanumeric keys.** If a legacy key can collide
with a bloodhound-generated one, that is account takeover, not a data bug. Prove the two
key formats are disjoint first.

### Announce Flow

1. Announce key validated, user looked up (a disabled account is refused); torrent
   identified by info_hash; parameters and IP validated.
2. The tracker's Redis checked for durability (cached; see [Redis](#redis)). In
   production a failing check refuses the announce here.
3. Client validated against the whitelist or blacklist.
4. The peer's record read from Redis, once, along with the torrent's live peers. A peer ID
   live in the swarm for another member is refused here: two members cannot share one,
   and letting the second through would overwrite or remove the first's record.
5. Anti-cheat judges the announce against that record.
6. The credit computed from the same record, and the ledger row written.
7. Only then Redis updated: the peer's record, the swarm counters and sets.
8. Bencoded peer list returned to the client. Within a minute or two
   `bloodhound:aggregate-ledger` adds the row to the member's totals.

A refusal at step 3, 4 or 5 writes a flagged ledger row and stops: nothing is credited
and Redis is not touched. A refusal at step 1 (a bad announce key, an unknown torrent,
missing or invalid parameters, an out-of-range port) or step 2 writes no row: there is no
member and torrent to record it against, or the tracker itself is refusing.

### Peer Storage

Peers live in Redis, under threepio's key prefix (`threepio.redis.prefix`, `marque:` by
default): a hash of peers per torrent, seeder and leecher counters, per-member and per-IP
sets, and swarm byte totals. [threepio's README](../threepio/README.md#redis) lists the
keys. Bloodhound adds a `suspicious` list (the last 1,000 anti-cheat events), a
`durability_sentinel` key and, while one is open, `recovery_until`.

A peer that has not announced for `threepio.peer_expiry` (an hour by default) is treated
as gone: it is left out of peer lists and counts as a new peer if it comes back. Its
record stays in Redis until the hourly sweep (`bloodhound:sync-swarm-counts`) removes it.
That sweep is the only way an expired peer leaves. A `stopped` announce removes its peer
at once.

#### Retained baselines

When the sweep removes an expired peer, it keeps the record for
`baseline_retention` (7 days by default) after its last announce, in a separate
`gone:{torrent}` hash. A member whose client comes back under the same peer ID without
having sent `stopped` (network lost, machine asleep, client killed) is then credited what
it moved in between, measured against that record, instead of nothing. The record is
used once and deleted. A `stopped` peer is never retained, because `stopped` closes the
session.

#### Recovery mode

If Redis does lose its data, the next peer it has no record of finds bloodhound's
`durability_sentinel` key gone while the ledger shows announces from the last
`peer_expiry`. That means the swarm those announces built is not there. Bloodhound logs a
warning and opens a recovery window for twice `peer_expiry` (two hours by default): long
enough for every peer that was in the swarm to announce again. During it, a peer that
Redis has no record of is looked up in the ledger instead: the last accepted announce from
that member, on that torrent, under that peer ID, and no older than `baseline_retention`.
A refused announce is never a baseline, and if the last row is a `stopped`, or `ended`, the
session is over and there is none.

Outside the window the announce path never reads the ledger.
`php artisan bloodhound:check-redis --recover` opens a window by hand.

An install upgrading from 7.x has no sentinel yet; the upgrade's migrations set it while
the swarm is known to be in Redis, so the first announces after upgrading do not read as a
lost Redis.

### Swarm Counts

Redis holds the live swarm, but a catalogue needs to filter and sort on it —
"hide dead torrents", "sort by seeders" — which SQL cannot do against Redis. So
each announce also writes `seeders` and `leechers` onto the `torrents` row
(skipping the write when nothing changed).

That projection alone is not enough. A peer that vanishes without sending
`stopped` — client killed, machine off, network gone — expires quietly in Redis,
and nothing announces afterwards to correct the row, which would sit there
advertising a swarm that no longer exists.

`bloodhound:sync-swarm-counts` closes that gap. It sweeps expired peers (keeping their
records as baselines, above) and writes the settled counts back, and is scheduled hourly:

```bash
php artisan bloodhound:sync-swarm-counts
php artisan bloodhound:sync-swarm-counts --chunk=1000   # tune batch size
```

It is registered on the scheduler automatically; you only need Laravel's
scheduler running. Torrent listings are unaffected unless you also set
`trove.hide_dead_torrents`.

## How an announce is credited

Every announce bloodhound accepts is credited once, at the moment it arrives, against the
peer's record in Redis: the counters and time of that peer's last accepted announce (its
baseline). The bytes and the seconds are both written on the announce's ledger row, and
every total is a sum of those rows. Nothing pairs one announce with another afterwards.

**Bytes** are the reported `uploaded` and `downloaded` minus the baseline's. A counter
that has gone backwards means the client restarted under the same peer ID and is counting
from zero again, so that direction is credited the reported figure.

**Seconds** are the time since the baseline's announce, capped at `threepio.peer_expiry`
(an hour by default). A client that goes quiet without saying so gets the hour, the point
at which the swarm already treats it as gone, rather than the whole night its laptop was
shut. The interval goes to **the state the peer was in when it began**: nothing left to
download means seeding, otherwise leeching. The interval in which a peer finishes
downloading is leech time, all of it, because that is what it was doing.

Each kind of announce:

- **First announce of a session** (no baseline: a new peer ID, the first after a stop, or
  a peer ID another member's record holds): credits nothing. The row has no priors.
- **Regular, `started` and `completed`**: credited as above. `started` does not close a
  session: a client that restarts quickly under the same peer ID carries on from its
  baseline. `completed` also records the completion.
- **`stopped`**: credits the bytes and seconds since the previous announce, against the
  record it removes. The protocol sends final counters on a stop, so they count. The
  session then ends; nothing is kept for it.
- **A refused announce** (client validation or anti-cheat): credits nothing and changes
  nothing in Redis. The next accepted announce is measured from the last accepted one, so
  its bytes are credited in full; its seconds are capped as usual.
- **A speed forfeit**: the direction over the cap credits 0, the other direction and the
  seconds are credited, and the baseline moves on (see [Anti-Cheat](#anti-cheat)).

**The ledger row is written first, then Redis.** If the insert fails, the client gets a
tracker error and retries against an untouched baseline, so nothing is lost. If Redis
fails after the insert, the next announce is measured against the same baseline and one
interval is credited twice; the audit names that row.

### Seedtime and leechtime

Bloodhound records how long each member spent seeding and leeching every torrent, on the
member (`seedtime`, `leechtime`) and per torrent (`torrent_user.seedtime`,
`torrent_user.leechtime`), in seconds, summed from the ledger like the byte totals.

**A member's time is the sum over their clients:** two clients seeding one torrent for an
hour earn two hours.

- **Time is counted in every `ratio_mode`.** It is a record of what happened, not a
  policy; switching mode later finds the history already there.
- **hound counts nothing.** A public tracker has no members to credit.

**Expect lower figures than some other trackers report.** Trackers that credit a fixed
announce interval per announce, or the whole gap however long, count time a client
wasn't there. Bloodhound counts only time it can stand behind: real elapsed time between
accepted announces, capped, never across a stop, and none for a refused announce.

## Anti-Cheat

Anti-cheat judges an announce against the same peer record the credit uses, so nothing is
checked against one baseline and credited against another. It refuses only what a
legitimate client doesn't do, and never refuses a client that is already in the swarm on
a count.

| Check | Applies to | Outcome |
|---|---|---|
| Port | Every announce | Refused: a port in `threepio.blacklisted_ports`, or outside 1–65535 |
| Minimum gap | Regular announces (no `event`) from a peer in the swarm, at the address it last announced from | Refused when sooner than `min_announce_gap` after its last accepted announce |
| Location limits | A peer not in the swarm, other than `stopped` | Refused at a new location past the seeding or leeching limit |
| Clients per torrent | A peer not in the swarm, other than `stopped` | Refused when the member already has `max_clients_per_torrent` live clients on the torrent |
| Speed | Every announce with a baseline | The direction over the cap is forfeited; the announce is accepted |
| Data anomaly | Every announce | More `left` than the torrent's size: reported, never refused |
| Swarm consistency | A 5% sample of announces | Swarm downloads well beyond uploads plus seeders' copies: reported, never refused |

**Minimum gap.** A hammer guard, 60 seconds by default, below
`threepio.min_announce_interval` (300, what clients are told) so a client that
re-announces early is not refused. `started`, `completed` and `stopped` are never refused
for timing: they report something that happened, and refusing them loses a completion or
leaves a ghost peer in the swarm. The gap is measured from the peer's record and a refusal
changes nothing, so a client retrying every 30 seconds is accepted every minute rather
than locked out.

The gap is from the same address only. A dual-stack client announces over IPv4 and IPv6
seconds apart with one peer ID; the second is accepted and credited only what moved since
the first.

**One announce per peer at a time.** A peer's announces are read, credited and written
under a short Redis lock, so two at once (the dual-stack case again) cannot both credit the
interval the first opens. An announce that cannot get the lock within two seconds is
refused and recorded; the client retries.

**Peer IDs are any 20 bytes** (BEP 3). One that isn't UTF-8 text, or holds a NUL, is
stored in the ledger as `x:` and its hex, the same way every time, so its rows still chain
and recovery still finds them. The user agent is stored scrubbed to valid UTF-8.

**Location limits.** A location is an IP address; for IPv6 it is the /64, because a
connection is usually handed a whole /64 and clients pick addresses from it freely.
Seeding and leeching are limited separately:
`max_seeding_locations_per_torrent` (3) and `max_leeching_locations_per_torrent` (1). The
leeching limit is what stops one account being shared to download. Only a **new**
location is counted against a limit:

- a peer already in the swarm, announcing from where it was, is never refused by a
  count, so a leecher that finishes becomes a seeder without being refused;
- a peer is counted again (not against itself) when it moves to another location, or
  turns from seeding to leeching, so starting as a seeder and then reporting bytes left
  does not get round the leeching limit;
- a client at a location the member already uses on that torrent, in the same state, is
  not a new location;
- a new peer is classed as seeding or leeching by the `left` it reports, and counted
  against the member's live peers in the same state.

The counts come from the torrent's live peers, which the response reads anyway.

**Clients per torrent.** A member may run `max_clients_per_torrent` (5) live peer IDs on
one torrent; only a new one past that is refused. Many peer IDs on one torrent pollute its
swarm and inflate its seeder count, and that is the abuse a cap exists for. How many
torrents a member, or an address, seeds is never limited: the members who seed thousands
of torrents around the clock from one seedbox are the ones keeping a site alive. (8.0
removed `max_connections_per_ip`, which counted across torrents and refused exactly them.)
An approved seedbox is not exempt from this or the location limits.

**Speed.** The bytes since the baseline over the real time elapsed, per direction,
against `max_upload_speed` / `max_download_speed` (100 MiB/s each), or the seedbox caps
(1,250,000,000 bytes/s, 10 Gbit/s) when the announce comes from one of the member's
approved [seedboxes](#seedboxes). A direction over its cap is **forfeited**: credited 0,
and the baseline moves on, so those bytes never land later. The other direction and the
seconds are still credited, and the row is flagged with a reason that names the
direction, the claimed rate, the cap and the bytes not credited. Refusing instead would
leave the baseline where it was, and the next announce would credit the same bytes over a
longer window. Lower the ordinary caps to what your members' home connections actually
do. A forfeit is not re-credited automatically, so a cap set too low costs honest members
their bytes.

**Events.** Only the checks that accept the announce fire `CheatDetected`, with `type`
`speed_violation`, `data_anomaly` or `swarm_anomaly` (the last with no user), and add an
entry to the Redis `suspicious` list. Refusals (port, gap, location, clients per torrent, and
client validation) fire nothing: each is a flagged ledger row with its reason, which
`AnnounceLogServiceInterface::flagged()` returns along with forfeits.

`anti_cheat.enabled = false` turns off every check above: nothing is refused, forfeited
or reported. Client validation has its own switch.

### Port Blacklist

Default blocked ports include Direct Connect (411-413), Kazaa (1214), eMule (4662), Gnutella (6346-6347), and legacy BitTorrent defaults (6881-6889).

## Seedboxes

A seedbox is an address or range a member registers as theirs. Announces from inside one
of a member's **approved** seedboxes are held to the seedbox speed caps instead of the
ordinary ones; that is all a seedbox changes. The point is to let you set the ordinary
caps to what home connections do without forfeiting a gigabit seedbox's bytes.

- **Registering.** A member registers an IPv4 or IPv6 address (stored as a /32 or /128)
  or a CIDR range, with an optional label. A range may be at most a /24 for IPv4 or a /48
  for IPv6 (`seedbox.min_prefix_v4`, `seedbox.min_prefix_v6`), so it can't take in other
  customers of the same provider. Each network is registered once per member.
- **Approval.** With `seedbox.require_approval` (the default) a new seedbox is pending
  until an admin approves it. Without it, registering approves it. Approving and revoking
  record who did it and when. A revoked seedbox's announces go back to the ordinary cap.
  A member can remove their own.
- **On the announce path** a member's approved networks come from the cache (five
  minutes) and are matched in PHP, so an announce costs no query. Every change made
  through bloodhound clears that member's cache, so it applies to their next announce.
- **Events:** `SeedboxRegistered`, `SeedboxApproved` and `SeedboxRevoked`, each with the
  `seedbox` (a trove `Seedbox`) and `by`, the deciding admin's user id (null on
  registration). Removing one fires nothing.

Bloodhound has no screens. It implements trove's `SeedboxesInterface`, and
[usarrs](../usarrs/README.md#seedboxes) renders the member screen (`/profile/seedboxes`)
and the admin screen (`/admin/seedboxes`) through it. Without usarrs, use the command:

```bash
php artisan bloodhound:seedbox list --status=pending
php artisan bloodhound:seedbox approve 12 --by=1    # --by: the admin's user id
php artisan bloodhound:seedbox revoke 12 --by=1
```

## Ending a session

A session is one client announcing one torrent. A client restarted under a new peer ID
somewhere else (a laptop moved to another network, a VPN switched on) is a new location,
and the old session stays live until it expires (`peer_expiry`, an hour). With the
leeching limit at one location, the restarted client is refused until then. So a member
can end the old session themselves; the refusal tells them to.

Ending one removes the peer from the swarm, and any retained baseline, and writes an
**`ended`** ledger row carrying the counters the session last reported. It credits
nothing: whatever the session moved since its last announce is lost, which is the price of
not waiting. Like a `stopped`, it closes the session, so if the old client announces again
under the same peer ID it starts a new one, the audit expects no baseline after it, and
recovery mode offers none. A member can end only their own sessions. It runs under the
peer's lock, as an announce does, so an announce from that session cannot land halfway
through.

Bloodhound implements trove's `TrackerSessionsInterface` (`sessionsFor()`, a member's live
sessions, leeching first; `endSession()`), and
[usarrs](../usarrs/README.md#sessions) renders it at `/profile/sessions`.

## Configuration

Published to `config/bloodhound.php`. Bloodhound's config is merged with the published
file at the top level only. A top-level key missing from your file comes from the shipped
config, environment variable and all. A published block, such as `anti_cheat`, replaces
the shipped one whole: a key missing from it falls back to the default in code, which is
the same as the shipped default, but its environment variable does nothing until the key
is in your file.

### Uploads: the private flag

| Key | Default | Description |
|-----|---------|-------------|
| `uploads.private_flag` | `require` | `require`, `disallow`, `warn_if_public`, `warn_if_private` or `allow` |

Bloodhound binds trove's `TorrentFilePolicyInterface`, so every upload (guise, disguise or
cennad) is checked against this. With `require`, a torrent without the `private` flag is
refused, and the uploader is told to recreate it with "private" ticked. Without the flag,
clients share the swarm over DHT and peer exchange, outside the tracker's accounting. The
flag is inside the info dictionary, so Marque never sets it for them: that would change the
info_hash. An unrecognised value is treated as `require`. See
[trove's README](../trove/README.md) for the modes.

### Downloads carry the member's announce URL

When a member downloads a torrent, the `.torrent` they get has `announce` set to their own
URL: this tracker's announce route with their key, in the path or the query exactly as
[Announce URL shape](#announce-url-shape) configures it. Its `comment` links back to the
torrent's page. Of the rest of the top level, only `encoding` is kept; everything else the
uploader's client wrote, `announce-list` included, is dropped. The info dictionary is
untouched, so the info_hash is the same for everyone. A member with no key yet (for
example, before verifying their address) is refused with that reason rather than given a
file their client can't announce with.

### Routing

Covered in full under [Announce URL shape](#announce-url-shape) above.

| Key | Default | Description |
|-----|---------|-------------|
| `routes.announce_path` | `announce` | Announce path, no leading slash |
| `routes.scrape_path` | `scrape` | Scrape path |
| `routes.key_source` | `path` | `path` or `query` |
| `routes.key_parameter` | `announce_key` | Route segment or query parameter name |
| `routes.key_pattern` | `[0-9a-zA-Z]{32}` | Regex the key must match — no delimiters, no anchors |

### Redis and baselines

| Key | Default | Description |
|-----|---------|-------------|
| `redis.durability` | `required` | `attested` when your provider makes Redis durable without AOF (`BLOODHOUND_REDIS_DURABILITY`); the eviction policy is still checked |
| `baseline_retention` | `604800` | Seconds an expired peer's record is kept as its baseline (7 days) |

See [Redis](#redis) and [Retained baselines](#retained-baselines).

### Timing

Timing, Redis and the peer response are threepio's settings, in `config/threepio.php`
(env vars `THREEPIO_*`). bloodhound keeps its peers through threepio, so it uses them;
publish threepio's config to change them.

| Key | Default | Description |
|-----|---------|-------------|
| `announce_interval` | `1800` | Seconds between announces (sent to clients) |
| `min_announce_interval` | `300` | Minimum interval clients are told to respect |
| `peer_expiry` | `3600` | Seconds before an inactive peer is treated as gone; also the cap on one announce's credited interval |

### Threepio's Redis settings

From `config/threepio.php`, as above.

| Key | Default | Description |
|-----|---------|-------------|
| `redis.connection` | `default` | Laravel Redis connection name: give the tracker its own |
| `redis.prefix` | `marque:` | Key namespace |

### Peer Response

From `config/threepio.php`, as above.

| Key | Default | Description |
|-----|---------|-------------|
| `max_peers_per_announce` | `50` | Max peers returned per announce |
| `peer_response_format` | `auto` | `auto`, `compact`, or `dictionary` |

### Client Validation

Bloodhound validates BitTorrent clients by peer ID. Default mode is `whitelist` with 9 pre-configured clients: qBittorrent, Deluge, Transmission, libtorrent, rTorrent, uTorrent, BitTorrent, Vuze and BiglyBT. A refused client gets a bencoded failure and a flagged ledger row.

| Key | Default | Description |
|-----|---------|-------------|
| `client_validation.enabled` | `true` | Enable client checks |
| `client_validation.mode` | `whitelist` | `whitelist` or `blacklist` |
| `whitelist` | *(see config)* | Allowed clients with version ranges, a top-level key |
| `blacklist` | *(see config)* | Blocked clients (Xunlei, etc.), a top-level key |

Each whitelist entry specifies a peer ID pattern, version format, and allowed version range. You can add custom clients or adjust version requirements.

### Anti-Cheat

Described under [Anti-Cheat](#anti-cheat) and [Seedboxes](#seedboxes). Speeds are bytes
per second.

| Key | Default | Description |
|-----|---------|-------------|
| `anti_cheat.enabled` | `true` | Master switch |
| `anti_cheat.max_upload_speed` | `104857600` | Ordinary cap, 100 MiB/s; over it is forfeited |
| `anti_cheat.max_download_speed` | `104857600` | Ordinary cap, 100 MiB/s |
| `anti_cheat.seedbox.max_upload_speed` | `1250000000` | Cap for an approved seedbox, 10 Gbit/s |
| `anti_cheat.seedbox.max_download_speed` | `1250000000` | Cap for an approved seedbox, 10 Gbit/s |
| `anti_cheat.seedbox.require_approval` | `true` | A new seedbox waits for an admin |
| `anti_cheat.seedbox.min_prefix_v4` | `24` | Widest IPv4 range a seedbox may be |
| `anti_cheat.seedbox.min_prefix_v6` | `48` | Widest IPv6 range a seedbox may be |
| `anti_cheat.min_announce_gap` | `60` | Min seconds between two regular announces from one peer |
| `anti_cheat.max_seeding_locations_per_torrent` | `3` | Locations a member may seed one torrent from |
| `anti_cheat.max_leeching_locations_per_torrent` | `1` | Locations a member may leech one torrent from |
| `anti_cheat.max_clients_per_torrent` | `5` | A member's live clients (peer IDs) on one torrent; only a new one is refused |
| `anti_cheat.block_datacenter_ips` | `false` | Not built yet; setting it does nothing <!-- check-docs: ignore — never built, needs a design decision first (#10813) --> |

### Completions

| Key | Default | Description |
|-----|---------|-------------|
| `completion_cooldown` | `86400` | Seconds after a completion before another `completed` from the same member on the same torrent counts as new |

`event=completed` is client-supplied, and a client restart or a second machine reports
it again for the same download, so time decides what counts as a new completion.

### Ratio — planned, not yet enforced

| Key | Default | Description |
|-----|---------|-------------|
| `ratio_mode` | `full` | `full`, `off` or `seedtime`. Read by nothing yet <!-- check-docs: ignore — documented but not yet enforced, read by nothing until #10732 --> |
| `min_ratio` | `0.5` | Read by nothing yet <!-- check-docs: ignore — not yet enforced, read by nothing until #10732 --> |
| `min_seedtime` | `86400` | Read by nothing yet <!-- check-docs: ignore — not yet enforced, read by nothing until #10732 --> |

Bytes and time are recorded in every mode; these keys will decide what is enforced (#10732).

### Announce Log

| Key | Default | Description |
|-----|---------|-------------|
| `announce_log.connection` | `null` | Database connection (null = app default) |
| `announce_log.retention_days` | `null` | Days to keep rows (null = forever) |

```env
BLOODHOUND_ANNOUNCE_LOG_CONNECTION=announce_log
BLOODHOUND_ANNOUNCE_LOG_RETENTION_DAYS=90
```

See [The ledger](#the-ledger).

### Removed in 8.0

| Removed key | What happens if your config still has it | Use instead |
|---|---|---|
| `announce_log.enabled` | Set to `false`, bloodhound refuses to boot, naming the key. `true` is harmless | Nothing: the ledger is always on |
| `queue.enabled`, `queue.connection`, `queue.queue` | Ignored, as they already were in 7.x (#10816) | Nothing: byte counts never travel through a queue |
| `anti_cheat.max_connections_per_torrent` | Ignored; a warning is logged once a day naming the replacements | `max_seeding_locations_per_torrent` and `max_leeching_locations_per_torrent` |
| `anti_cheat.max_connections_per_ip` | Ignored; a warning is logged once a day | `max_clients_per_torrent`, a member's clients on one torrent. Nothing limits how many torrents one address seeds |

The ledger can't be turned off because every credit is written to it and nowhere else: a
tracker without it would credit nobody anything.

## The ledger

`announce_log` is a full-detail, permanent history of every announce from a known member
on a known torrent: each started, regular, completed and stopped request, accepted or
refused. It is the **source of truth for ratio and seedtime**. User totals and per-torrent
totals are projections summed from it, and a wrong number can only be detected — let
alone corrected — by comparing it against this. Ratio is what gets people banned.

The row is written **synchronously**, before Redis and before the response. A queue would
mean the only copy of a credit lived in a job payload, and a lost job would be a lost
credit with nothing left to re-derive it from.

### What a row holds

| Column | Meaning |
|---|---|
| `user_id`, `torrent_id`, `peer_id` | Who, on what, from which client session |
| `event` | `started`, `regular`, `completed`, `stopped`; `ended` for a session the member ended; or `opening_balance` for a migration's carried total |
| `ip`, `port`, `user_agent` | Where from |
| `uploaded`, `downloaded`, `left` | The cumulative counters the client reported |
| `upload_delta`, `download_delta` | Bytes this row credits |
| `seed_seconds`, `leech_seconds` | Seconds this row credits (at most `peer_expiry`, and only one of the two) |
| `prior_up`, `prior_down`, `prior_at` | The baseline those were measured against: its counters and the time of its announce. Null when there was none |
| `anti_cheat_flagged`, `anti_cheat_reason` | A refusal or a forfeit, and why |
| `created_at` | When the credit was measured, which is also the peer's new last announce |

**Every row carries its own credit.** A refusal or a first announce credits 0 bytes and 0
seconds, so it adds nothing to any sum by construction. Storing the baseline beside the
credit is what lets each row be checked on its own, and each peer's rows checked as a
chain (see [Audit](#audit)). Both the client's cumulative claim and the credited delta
are kept: a client whose claimed totals don't match its own delta history is lying about
something.

The table is append-only. Rows are never modified after they're written, so there is no
`updated_at`. Credit that is not a row of its own (what pruning removed, and what the
upgrade carried over) lives in `ledger_carry`: one row per member and torrent, with
`uploaded`, `downloaded`, `seedtime` and `leechtime`, on the same connection as
`announce_log`. **The ledger is its rows plus its carry**, and everything below reads it
that way.

### Isolating it on a separate database

`announce_log.connection` takes any connection name from `config/database.php`. Point it at a second database - same engine or not - and all reads and writes for `announce_log` and `ledger_carry` go there, with no other change. Migrations follow it too.

This is the mechanism for keeping a high-write-volume table off your main database. Note that `user_id` and `torrent_id` carry no foreign key constraints precisely so this works: a real FK would require those tables to live on the same connection, which is the thing you're trying to avoid.

### Aggregation

`bloodhound:aggregate-ledger` runs every minute and folds new rows into `torrent_user` and
the users table: per batch of 1,000 rows, the sums of each member-and-torrent's bytes and
seconds, written in a fixed handful of statements. A row that credits nothing writes
nothing. A fold is a sum, so a batch boundary splits nothing.

- **Totals trail the ledger by a run or two.** A scheduled run folds only up to the
  highest id the previous run saw (the horizon, on `ledger_cursors`). An id is handed out
  when an insert starts, not when it commits, so a later id can become visible first;
  folding to the head could step over a row still committing, and it would never be
  counted. Everything below the horizon has had a whole minute to commit. A backlog after
  an outage drains in two runs.
- **Two folds can't count a batch twice.** Each batch locks the cursor row first and
  reads its position under the lock, so a second fold (a manual run beside the scheduler,
  two hosts without a shared cache lock) waits, then starts from where the first left
  off. The advance is a compare-and-swap on the position read: if the cursor moved
  anyway, the batch rolls back and is redone. `withoutOverlapping()` on the schedule is a
  courtesy, not the control.
- **Crash-safety is the transaction.** The totals and the cursor advance commit together
  or not at all, so a redone batch is idempotent.

Measured on a development machine across the four engines, folding 200,000 rows took 26
to 37 seconds, depending on the engine. `bloodhound:reconcile-ledger` reports how many rows
are waiting; watch that number at your busiest hour.

### Verifying the numbers

The commands, and the reason they exist: before the ledger, a byte count could be lost
or corrupted and **nothing anywhere would know**. The totals were accumulators with
nothing behind them, so a wrong number stayed wrong forever and the first anyone heard of
it was a user disputing a ban.

#### Reconcile

`bloodhound:reconcile-ledger` (scheduled daily) compares every member's and every
member-and-torrent's bytes, seedtime and leechtime against the ledger: the sum of the rows
the aggregator has folded, plus carry. **It reports; it does not repair.** You should learn
a number went wrong before anything changes it back. Rows not yet folded are reported as a
backlog rather than as drift — an alert that cries wolf is an alert nobody reads. Drift is
logged as a warning and the command exits 1.

#### Audit

`bloodhound:audit-ledger` checks the ledger against itself.

**Arithmetic, per row.** Bytes: each direction is the reported counter minus the prior, or
the reported counter when it went backwards. A forfeit may have zeroed a direction, so a
flagged row may show 0 instead. Seconds: `seed_seconds + leech_seconds` equals
`created_at - prior_at`, capped at `peer_expiry`, all in one of the two. A row with no
baseline credits nothing. The cap is today's `peer_expiry`, so if you change it, rows
written under the old one report as differences.

**The chain, per member, torrent and peer ID, in order.** Each row's baseline should be
what the previous row reported, and its `prior_at` the previous row's `created_at`. After
a `stopped`, or a session the member ended (`ended`, which has no baseline itself), the
next row starts a new session with no baseline. Refusals and opening balances are not part
of the chain, and stops and ended sessions end it cleanly, so none of them is ever
reported as a break. What is reported, with the reason:

- **Redis lost this peer:** a row with no baseline that should have had one. Not reported
  when the gap is longer than `baseline_retention`, since no baseline is kept that long.
- **Duplicate interval:** two rows measured against the same baseline: one interval
  credited twice (a Redis write that failed after its row, or two announces from one peer
  at once).
- **The baseline does not continue the previous announce**, **a baseline after the
  session stopped** (or **ended**), and **an ended session with a baseline**.

With a persistent Redis a break should not happen; when one does, you learn which row.

Rows written by 7.x, before rows carried their own time, are held to the rules they were
written under: bytes only, with a counter that went backwards credited 0, and they are not
checked as a chain. The upgrade records where they end.

#### Rebuild

`bloodhound:rebuild-totals` recomputes user and per-torrent totals from the ledger and its
carry, for everyone or `--user=`: bytes, seedtime and leechtime. It takes the aggregator's
cursor lock first, so a fold can't commit between its reset and its write; a fold that was
waiting carries on from the same position afterwards. On MySQL a fold waiting through a
long rebuild may hit `innodb_lock_wait_timeout` and fail; the next run retries. A rebuild
leaves the cursor where it is, and leaves completion dates and counts alone, because they
are not derivable from the rows.

On a development machine, rebuilding from 200,000 rows took 29 to 95 seconds depending on
the engine.

### Retention, pruning and carry

`retention_days` is `null` by default, which means **keep everything forever**, and the
table grows without bound on a busy tracker. That default is deliberate - how long to keep
the source of truth for ratio is your call, not ours - but it is your job to make it.

Set `retention_days` and the scheduled `bloodhound:prune-announce-log` command (daily)
keeps the table bounded:

```bash
php artisan bloodhound:prune-announce-log
```

It no-ops when no retention is configured, and reports what it did either way.

**Only rows the aggregator has folded are pruned.** A row still waiting is credit not yet
in the totals, so it is kept whatever its age, and the command says how many it withheld.

**What it deletes, it carries.** Each row carries its own credit, so what a deleted row
contributed is exactly its own bytes and seconds. The prune works in chunks of 1,000 rows
by id; each chunk's bytes and seconds are added to `ledger_carry` per member and torrent,
and the rows deleted, in one transaction. If anything fails, the delete rolls back with
the carry. No row is kept to protect another, so the table stops at your retention window,
and reconcile and rebuild stay exact: a prune followed by a rebuild reproduces the
pre-prune totals, which was measured on all four engines with 200,000 rows. That prune took
34 to 69 seconds, depending on the engine.

Carry is signed, so that a future correction can be recorded as an adjustment. Neither a
prune nor the upgrade ever writes a negative.

### Querying the log

`AnnounceLogServiceInterface` is the read side. Resolve it from the container:

```php
use Marque\Bloodhound\Contracts\AnnounceLogServiceInterface;

public function __construct(
    private readonly AnnounceLogServiceInterface $log,
) {}
```

| Method | Answers |
|--------|---------|
| `forUser(int $userId, ?Carbon $since = null)` | What has this user been doing? |
| `forTorrent(int $torrentId, ?Carbon $since = null)` | What happened on this torrent? |
| `forUserAndTorrent(int $userId, int $torrentId)` | This user's session history on one torrent - the ratio-dispute query |
| `flagged(?Carbon $since = null)` | Every refusal and forfeit |
| `byIp(string $ip, ?Carbon $since = null)` | Multi-account / IP correlation |

All return a `Collection` of `AnnounceLog` models, newest first.

```php
$recent = $this->log->forUser($user->id, now()->subDays(7));
$disputed = $this->log->forUserAndTorrent($user->id, $torrent->id);
```

Results aren't paginated. The log is queried deliberately - an investigation, a dispute - not rendered on a hot path, and `$since` is the intended way to bound a result set on a tracker with real volume. Each method's query is shaped to use the table's indexes, so passing `$since` is cheap rather than a post-filter.

Bloodhound ships no UI for this. Browsing the log is left to your application.

### Private trackers only

This is a Bloodhound feature and has no equivalent in [marque/hound](https://packagist.org/packages/marque/hound). Public trackers are deliberately anonymous - `hound` records no user against an announce at all - so ratio verification and cheat investigation aren't concepts that apply there.

## Commands

| Command | Scheduled | Does |
|---|---|---|
| `bloodhound:aggregate-ledger` | Every minute | Folds new ledger rows into the totals (up to the horizon), and checks Redis durability |
| `bloodhound:reconcile-ledger` | Daily | Reports totals that disagree with the ledger |
| `bloodhound:prune-announce-log` | Daily | Deletes rows past `retention_days`, carrying what they credited |
| `bloodhound:sync-swarm-counts` | Hourly | Sweeps expired peers, keeping their baselines; settles swarm counts |
| `bloodhound:check-redis` | — | Checks AOF and the eviction policy; `--recover` opens a recovery window |
| `bloodhound:audit-ledger` | — | Checks every row's arithmetic and every peer's chain |
| `bloodhound:rebuild-totals` | — | Recomputes totals from the ledger and carry; `--user=` for one member |
| `bloodhound:seedbox` | — | `list` (`--status=`), `approve` or `revoke` a seedbox (`--by=`) |
| `bloodhound:backfill-ledger` | — | Once, after upgrading from 7.x: credits pre-8.0 history its time and stopped bytes |
| `bloodhound:record-pruned-history` | — | Once, if needed: accounts for rows a pre-8.0 prune deleted without carrying them |

## Upgrading from 7.x to 8.0

**Before upgrading:**

1. **Make Redis durable.** Point `THREEPIO_REDIS_CONNECTION` at a database or instance of
   its own and set `appendonly yes`, `appendfsync everysec` and
   `maxmemory-policy noeviction` (see [Redis](#redis)). You can confirm it on 7.x with
   `redis-cli INFO persistence` (look for `aof_enabled:1`) and `redis-cli INFO memory`
   (`maxmemory_policy:noeviction`), with your tracker's connection flags. If your provider
   offers no AOF and makes the data durable another way, set
   `BLOODHOUND_REDIS_DURABILITY=attested`. **In production, 8.0 refuses announces until
   this is right.**
2. **If you have ever pruned the announce log, don't run `rebuild-totals` on 7.x.** 7.x's
   prune kept nothing of what it deleted, and a rebuild there would cut those members'
   ratios.
3. **Update a published `config/bloodhound.php`.** Delete `announce_log.enabled` (with
   it set to false, 8.0 won't boot) and the `queue` block. Replace
   `anti_cheat.max_connections_per_torrent` with `max_seeding_locations_per_torrent` and
   `max_leeching_locations_per_torrent`. Add the new keys you want to set by environment:
   `redis.durability`, `baseline_retention` and the `anti_cheat.seedbox` block. Simplest
   is to re-publish and re-apply your changes.
4. **Delete `max_connections_per_ip`** from a published config: it is gone, and nothing
   limits how many torrents one address seeds. `max_clients_per_torrent` caps a member's
   clients on one torrent instead.
5. **Review the speed caps.** Over the cap now forfeits the bytes instead of refusing the
   announce. Lower the ordinary caps to suit your members, and have seedbox users register
   their seedboxes (and approve them) so they are held to the seedbox cap.

**Upgrade** bloodhound to ^8.0 and threepio to ^4.0 in one `composer require` (with hound's
matching release, if a hound site shares the install), then `php artisan migrate`. The
migrations:

- add `seed_seconds`, `leech_seconds` and `prior_at` to `announce_log`, and `leechtime` to
  the users table and `torrent_user`;
- create `ledger_carry` and `seedboxes`, and add the horizon to `ledger_cursors`, set to
  the head of the ledger so folding carries on;
- record the last id written by 7.x, so the audit holds earlier rows to 7.x's rules;
- record **time opening balances**: stored seedtime and leechtime that no ledger row
  explains (7.x always wrote seedtime as 0, so this is imported or hand-entered time) as
  carry, per torrent, then the rest of each member's total on torrent 0. Without it
  reconcile would report that time as drift and a rebuild would erase it. Running it again
  records nothing;
- if `retention_days` is set, record **pruned history** (below), and say how much it
  covered.

Nothing rewrites an existing row, and no rebuild is needed. Reconcile is clean afterwards,
unless you pruned in the past and retention is now off (step 9).

**After upgrading:**

6. **Run `php artisan bloodhound:check-redis`.** The aggregator also runs the check every
   minute.
7. **Delete the old Redis keys once**, with your tracker's connection flags (`-h`, `-p`,
   `-n`) on both `redis-cli` calls, and your `redis.prefix` in place of `marque:`:

   ```bash
   redis-cli --scan --pattern '*marque:ip:*:peers' | xargs -r redis-cli del
   redis-cli --scan --pattern '*marque:announce_time:*' | xargs -r redis-cli del
   ```

   The per-IP sets are replaced by `ip:{ip}:sessions`. The `announce_time` keys expire on
   their own within two minutes; the gap is now read from the peer's record. The leading
   `*` matches the prefix Laravel adds to Redis keys.
8. **Optional, recommended: `php artisan bloodhound:backfill-ledger`.** 7.x credited no
   time, and credited nothing for the bytes a `stopped` announce reported. The ledger
   proves both, and this credits them: each 7.x session's seconds between consecutive
   accepted announces (capped, attributed by state, never across a stop), and each stop's
   bytes against the announce before it. It writes the result to `ledger_carry` and adds
   it to the totals in one transaction under the aggregator's lock, so no rebuild is
   needed. It rewrites no row, runs once (a second run does nothing), and refuses until the
   aggregator has folded all of 7.x history, which the first scheduled run after
   migrating normally does.
   Members' seedtime and ratio move when you run it. Without it, time starts at the
   upgrade and nothing is wrong.
9. **If you pruned in the past but retention is now off**, run
   `php artisan bloodhound:record-pruned-history` (below).

### If you pruned on an earlier version

Before 8.0, every prune deleted credited rows and kept nothing. Those members' totals are
still right, but the ledger no longer explains them: reconciliation reports them as
drift, and **running `rebuild-totals` would cut their ratios.** Don't run a rebuild until
this is done.

The upgrade migration does it if `retention_days` is set. If you pruned in the past but
have since turned retention off, run it yourself:

```bash
php artisan bloodhound:record-pruned-history
```

It records each gap as carry, and only what pruning could have caused, so real drift stays
visible:

- **per member and torrent**, because every pair that moved bytes has its own total, and
  pruned credit always leaves a gap there. A gap only on a member's own total means the
  two totals disagree with each other, which pruning can't cause.
- **only where the stored total is above the ledger**, because pruning removes credit and
  never adds it.
- **bytes only**, because 7.x recorded no time.

Like the opening balance, what it records asserts the totals were correct as of the
upgrade, not that they were ever right. Running it again changes nothing.

### Upgrading from before the ledger (bloodhound before 5.0)

The migration writes one `opening_balance` ledger row per member, carrying their
pre-ledger `uploaded`/`downloaded` forward, then folds it immediately so nobody reads as
having zero ratio. An install migrating from before 5.0 straight to 8.0 has the fold done
by the 8.0 migration that adds the time columns, once the columns the aggregator needs
exist.

That row asserts the old total was correct **as of migration**. It is not evidence that
it ever was — the per-announce history was never kept, which is exactly why the ledger now
exists. Per-torrent history likewise starts empty, so hit-and-run enforcement is only
meaningful for torrents grabbed after the upgrade.

## Events

| Event | Fired When | Properties |
|-------|------------|------------|
| `TorrentCompleted` | Peer finishes download | userId, torrentId, ip, userAgent |
| `CheatDetected` | A speed forfeit, data anomaly or swarm anomaly (not a refusal) | type, userId, torrentId, reason |
| `SeedboxRegistered` | A member registers a seedbox | seedbox, by (null) |
| `SeedboxApproved` | An admin approves one | seedbox, by |
| `SeedboxRevoked` | An admin revokes one | seedbox, by |

`TorrentCompleted` automatically records a snatch (completion record) via the built-in listener.

Listen to these in your app for custom behaviour:

```php
use Marque\Bloodhound\Events\CheatDetected;

Event::listen(CheatDetected::class, function ($event) {
    // Ban user, notify admin, etc.
});
```

## Middleware

Bloodhound applies `BlockBrowsers` middleware to tracker endpoints. This rejects requests from web browsers (detected by cookies, Accept-Language headers, and user agent strings) - only BitTorrent clients should hit these endpoints.

## License

MIT. See [LICENSE](LICENSE).
