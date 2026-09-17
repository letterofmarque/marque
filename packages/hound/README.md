# Marque Hound

Public BitTorrent tracker for the [Marque](https://github.com/letterofmarque/marque)
platform. Open announce and scrape — no accounts, no announce keys, no ratio.

Hound is the deliberately small half of the tracker pair. Where
[marque/bloodhound](https://packagist.org/packages/marque/bloodhound) knows who every
peer is and holds them to a ratio, hound knows nothing about anybody. That is not a
missing feature — a public tracker has no accountable user, so most of what bloodhound
does has nothing to attach itself to.

## Starting from scratch?

Hound is the tracker half only — no frontend, no upload UI. For a complete public
tracker, install the set:

```bash
composer require marque/trove marque/hound marque/disguise
```

That resolves `marque/threepio` and `marque/deck` for you.

Running a private tracker with accounts and ratio instead? Use
[marque/bloodhound](https://packagist.org/packages/marque/bloodhound) and
[marque/guise](https://packagist.org/packages/marque/guise) in place of hound and
disguise.

> ⚠️ **Never install hound alongside bloodhound.** Hound registers an open `announce`
> route with no key and no authentication. A private tracker that merely has hound in
> `vendor/` is carrying a keyless announce endpoint beside its authenticated one, and
> anyone who finds it can transfer without ever touching ratio accounting. Pick one
> tracker package.

## Installation

Requires [marque/trove](https://packagist.org/packages/marque/trove) and
[marque/threepio](https://packagist.org/packages/marque/threepio), which supplies the
protocol primitives and Redis peer storage.

```bash
composer require marque/hound
```

Publish the config:

```bash
php artisan vendor:publish --tag=hound-config
php artisan vendor:publish --tag=threepio-config
```

Hound ships no migrations of its own — it reads and writes trove's `torrents` table and
keeps live peers in Redis.

## Endpoints

| Endpoint | Name | Purpose |
|----------|------|---------|
| `GET /announce` | `tracker.announce` | Peer announces (started, completed, stopped) |
| `GET /scrape` | `tracker.scrape` | Swarm statistics |

**No announce key in the URL.** That is the whole difference from bloodhound, whose
routes are `/announce/{announce_key}`. Anyone holding the .torrent can announce.

Both routes run outside the `web` middleware group — no session, no CSRF, no cookies —
and behind threepio's `BlockBrowsers` middleware, which rejects anything that looks like
a web browser rather than a BitTorrent client.

### Announce flow

1. Required parameters validated (`info_hash`, `peer_id`, `port`, `uploaded`, `downloaded`, `left`)
2. Torrent looked up by info_hash — an unregistered hash is refused
3. Port checked against threepio's blacklist
4. IP peer count checked against the limit
5. Peer upserted in Redis under user ID `0` (anonymous)
6. Swarm counts projected onto the `torrents` row when they've changed
7. Bencoded peer list returned

There is no user lookup, no anti-cheat pass, no byte accounting and no ledger. A public
announce touches the database only to identify the torrent and, when the swarm has
actually moved, to update its counts.

### Scrape

Accepts one or more `info_hash` parameters and returns `complete` / `downloaded` /
`incomplete` per torrent. Capped at **50 hashes per request**; extras are dropped rather
than erroring. Unknown hashes are silently omitted from the response, per convention.

## What hound does not do

Worth stating plainly, because the absences are design decisions rather than gaps:

| Not here | Why |
|---|---|
| Announce keys / user identity | Public tracker; peers are anonymous by definition |
| Ratio, upload/download accounting | Nothing to attribute bytes to |
| The announce ledger | See [bloodhound's README](../bloodhound/README.md) — it is a private-tracker feature |
| Anti-cheat, client whitelisting | Cheating presumes a user with something to gain |
| Snatch records | No user to record a snatch against |

### `times_completed` means something different here

Hound increments `times_completed` on every `completed` event it sees. It is a blind
increment, and it has to be: with no user attached to an announce, a client restarting
mid-download is indistinguishable from a second person finishing.

So on a public tracker the number means **"completed events seen"**, not "distinct
completions". Bloodhound's figure for the same column means the latter. The two are not
comparable across the packages — that is the honest best a tracker with no accountable
user can do.

## Swarm counts on the torrent row

Live peers are in Redis, but a catalogue needs to filter and sort on swarm state — "hide
dead torrents", "sort by seeders" — and SQL cannot query Redis. So each announce also
writes `seeders` and `leechers` onto the `torrents` row, skipping the write entirely when
neither has changed (which is most announces).

Note the gap this leaves, and that hound does not close: a peer that vanishes without
sending `stopped` expires quietly out of Redis, and with nothing announcing afterwards the
row keeps advertising a swarm that no longer exists. Bloodhound has
`bloodhound:sync-swarm-counts` scheduled hourly to settle this; **hound ships no
equivalent command**. On a public tracker with steady traffic the next announce corrects
it, but a torrent whose swarm empties completely will hold its last-known counts.

## Configuration

Hound's own config is deliberately thin — the shared protocol settings (intervals, peer
expiry, Redis, port blacklist, response format) live in
[threepio](../threepio/README.md).

Published to `config/hound.php`:

### IP limiting

The primary abuse prevention for a public tracker, and with no accounts it is close to the
only one available.

| Key | Default | Description |
|-----|---------|-------------|
| `ip_limiting.enabled` | `true` | Enable the per-IP peer cap |
| `ip_limiting.max_per_ip` | `50` | Max concurrent peers from one IP |

```env
HOUND_IP_LIMITING=true
HOUND_MAX_PER_IP=50
```

Peers over the cap get a bencoded `Too many connections from your IP` failure. The count
is across all torrents, not per torrent — a single IP seeding 50 torrents is at the limit.
Raise it if you expect legitimate NAT'd or institutional traffic.

### Logging

| Key | Default | Description |
|-----|---------|-------------|
| `logging.enabled` | `false` | Enable announce logging |
| `logging.channel` | `stack` | Laravel log channel |

```env
HOUND_LOGGING=false
HOUND_LOG_CHANNEL=stack
```

This is Laravel logging, not bloodhound's announce ledger — there is no queryable history
table and no reconciliation. Off by default because announce volume makes for large logs
fast.

### Inherited from threepio

Set these in `config/threepio.php`, not here:

| Key | Default | Description |
|-----|---------|-------------|
| `announce_interval` | `1800` | Seconds between announces (sent to clients) |
| `min_announce_interval` | `300` | Minimum allowed interval |
| `peer_expiry` | `3600` | Seconds before inactive peers are dropped |
| `max_peers_per_announce` | `50` | Ceiling on peers returned, regardless of `numwant` |
| `peer_response_format` | `auto` | `auto`, `compact`, or `dictionary` |
| `blacklisted_ports` | *(see config)* | Direct Connect, Kazaa, eMule, Gnutella, legacy BT range |
| `redis.connection` | `default` | Laravel Redis connection name |
| `redis.prefix` | `marque:` | Key namespace |

## Behind a proxy

Hound rejects private and reserved IP ranges **in production only** — under any other
environment they pass, so local and staging work without special handling.

If you run behind Cloudflare, a load balancer or any reverse proxy, configure Laravel's
[trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies) before
going live. Without it every peer registers with your proxy's address, which collapses
them into one IP and trips the IP limit immediately.

## Requirements

- PHP 8.3+
- Laravel 13+
- Redis
- [marque/trove](https://packagist.org/packages/marque/trove)
- [marque/threepio](https://packagist.org/packages/marque/threepio)

## License

MIT
