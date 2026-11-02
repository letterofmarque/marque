<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Carbon;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Support\Location;
use Marque\Threepio\Support\PeerAddresses;

/**
 * Reads the ledger for the account-sharing patterns no single announce can
 * show (#11133). It reports; it never refuses or changes a credit.
 *
 * - An alternating peer: one peer ID keeps going back to a network it left,
 *   within one address family (IPv4 A, then B, then A again). One client
 *   does not do that unless a VPN is being switched on and off, so it is
 *   reported as suspected, for staff to look at.
 * - An unsustained link: an ambiguous location (`ambiguous_locations=flag`)
 *   linked two places, the member has had other peers at both, and the peer
 *   that linked them did not keep switching between them. A dual-stack
 *   client switches families every announce interval; one peer ID that
 *   announced from two places to set the link up does not.
 *
 * Only accepted announces are read: a refused one never put the peer
 * anywhere. One pass in id order, with a little state per session.
 */
final class LocationSharingDetector
{
    /**
     * Switches back to an already-left network, for an alternating peer.
     */
    public const ALTERNATIONS = 2;

    /**
     * Switches between a link's two places that make it sustained.
     */
    public const SUSTAINED = 3;

    /**
     * Read the ledger from $since. Links come from ambiguous rows since
     * $linksSince (earlier, so a link set up days ago is still checked
     * against what the member does now), defaulting to $since.
     *
     * @return list<array{kind: string, user_id: int, torrent_id: int, peer_id: string, places: list<string>, count: int}>
     */
    public function detect(Carbon $since, ?Carbon $linksSince = null): array
    {
        $from = $this->firstIdSince($since);

        if ($from === null) {
            return [];
        }

        /** @var array<string, array{last: array<int, string>, seen: array<int, array<string, true>>, returns: int, places: array<string, true>, user: int, torrent: int, peer: string, at: ?string}> $sessions */
        $sessions = [];
        /** @var array<string, array<string, array<string, true>>> $members  "user:torrent" => place => peer IDs */
        $members = [];
        /** @var array<string, array{from: string, to: string, switches: int, user: int, torrent: int, peer: string}> $links */
        $links = $this->earlierLinks($linksSince, $from);

        AnnounceLog::query()
            ->select(['id', 'user_id', 'torrent_id', 'peer_id', 'event', 'ip', 'anti_cheat_flagged', 'anti_cheat_reason', 'prior_up'])
            ->where('id', '>=', $from)
            ->whereNotIn('event', [AnnounceLog::EVENT_OPENING_BALANCE, AnnounceLog::EVENT_ENDED])
            ->chunkById(5_000, function ($rows) use (&$sessions, &$members, &$links) {
                foreach ($rows as $row) {
                    // A refusal: flagged, and no baseline. It placed nobody.
                    if ($row->anti_cheat_flagged && $row->prior_up === null) {
                        continue;
                    }

                    $ip = (string) $row->ip;
                    $family = PeerAddresses::family($ip);

                    if ($family === null) {
                        continue;
                    }

                    $place = Location::of(PeerAddresses::normalise($ip));
                    $key = "{$row->user_id}:{$row->torrent_id}:{$row->peer_id}";
                    $session = $sessions[$key] ?? ['last' => [], 'seen' => [], 'returns' => 0, 'places' => [], 'user' => (int) $row->user_id, 'torrent' => (int) $row->torrent_id, 'peer' => (string) $row->peer_id, 'at' => null];
                    $previous = $session['at'];

                    // Back on a network this peer had left, in the same family.
                    if (isset($session['last'][$family]) && $session['last'][$family] !== $place && isset($session['seen'][$family][$place])) {
                        $session['returns']++;
                    }

                    if (isset($links[$key]) && $previous !== null && $previous !== $place
                        && in_array($previous, [$links[$key]['from'], $links[$key]['to']], true)
                        && in_array($place, [$links[$key]['from'], $links[$key]['to']], true)) {
                        $links[$key]['switches']++;
                    }

                    if ($this->ambiguous($row)) {
                        // The row before it may be before the window, when
                        // the link was set up across the run's boundary.
                        $before = $previous ?? $this->placeBefore($row);

                        if ($before !== null && $before !== $place) {
                            $links[$key] ??= ['from' => $before, 'to' => $place, 'switches' => 0, 'user' => (int) $row->user_id, 'torrent' => (int) $row->torrent_id, 'peer' => (string) $row->peer_id];
                        }
                    }

                    $session['last'][$family] = $place;
                    $session['seen'][$family][$place] = true;
                    $session['places'][$place] = true;
                    $session['at'] = $place;
                    $sessions[$key] = $session;

                    $members["{$row->user_id}:{$row->torrent_id}"][$place][(string) $row->peer_id] = true;
                }
            });

        $findings = [];

        foreach ($sessions as $session) {
            if ($session['returns'] >= self::ALTERNATIONS) {
                $findings[] = $this->finding('alternating_peer', $session, array_keys($session['places']), $session['returns']);
            }
        }

        // Every link, including one whose peer has gone quiet since setting
        // it up: what matters is what the member does now.
        foreach ($links as $link) {
            if ($link['switches'] >= self::SUSTAINED) {
                continue;
            }

            // Other peers of the member at both of the link's places.
            $places = $members["{$link['user']}:{$link['torrent']}"] ?? [];
            $others = fn (string $place) => array_diff_key($places[$place] ?? [], [$link['peer'] => true]) !== [];

            if ($others($link['from']) && $others($link['to'])) {
                $findings[] = $this->finding('unsustained_link', $link, [$link['from'], $link['to']], $link['switches']);
            }
        }

        return $findings;
    }

    /**
     * Links set up between $since and the window: an ambiguous row, and the
     * place its peer announced from just before.
     *
     * @return array<string, array{from: string, to: string, switches: int, user: int, torrent: int, peer: string}>
     */
    private function earlierLinks(?Carbon $since, int $before): array
    {
        $from = $since === null ? null : $this->firstIdSince($since);

        if ($from === null || $from >= $before) {
            return [];
        }

        $links = [];

        AnnounceLog::query()
            ->select(['id', 'user_id', 'torrent_id', 'peer_id', 'ip', 'anti_cheat_flagged', 'anti_cheat_reason', 'prior_up'])
            ->where('anti_cheat_flagged', true)
            ->where('id', '>=', $from)
            ->where('id', '<', $before)
            ->where('anti_cheat_reason', 'like', AntiCheatService::AMBIGUOUS_LOCATION.'%')
            ->orderBy('id')
            ->each(function (AnnounceLog $row) use (&$links) {
                $place = Location::of(PeerAddresses::normalise((string) $row->ip));
                $previous = $this->placeBefore($row);

                if ($previous !== null && $previous !== $place) {
                    $links["{$row->user_id}:{$row->torrent_id}:{$row->peer_id}"] ??= ['from' => $previous, 'to' => $place, 'switches' => 0, 'user' => (int) $row->user_id, 'torrent' => (int) $row->torrent_id, 'peer' => (string) $row->peer_id];
                }
            });

        return $links;
    }

    /**
     * Was this row accepted as an ambiguous location under `flag`?
     */
    private function ambiguous(AnnounceLog $row): bool
    {
        return $row->anti_cheat_flagged
            && $row->prior_up !== null
            && str_starts_with((string) $row->anti_cheat_reason, AntiCheatService::AMBIGUOUS_LOCATION);
    }

    /**
     * Where the row's peer announced from last before it, accepted.
     */
    private function placeBefore(AnnounceLog $row): ?string
    {
        $before = AnnounceLog::query()
            ->where('torrent_id', $row->torrent_id)
            ->where('peer_id', $row->peer_id)
            ->where('id', '<', $row->id)
            ->where('user_id', $row->user_id)
            ->where(fn ($query) => $query->where('anti_cheat_flagged', false)->orWhereNotNull('prior_up'))
            ->orderByDesc('id')
            ->value('ip');

        return $before === null ? null : Location::of(PeerAddresses::normalise((string) $before));
    }

    /**
     * @param  array{user: int, torrent: int, peer: string}  $session
     * @param  list<string>  $places
     * @return array{kind: string, user_id: int, torrent_id: int, peer_id: string, places: list<string>, count: int}
     */
    private function finding(string $kind, array $session, array $places, int $count): array
    {
        return [
            'kind' => $kind,
            'user_id' => $session['user'],
            'torrent_id' => $session['torrent'],
            'peer_id' => $session['peer'],
            'places' => $places,
            'count' => $count,
        ];
    }

    /**
     * The first ledger id written at or after $since.
     *
     * Rows are written in time order, so ids rise with created_at, and a
     * binary search on the primary key finds it in a few dozen lookups. A
     * created_at range would read the table: nothing indexes created_at on
     * its own.
     */
    private function firstIdSince(Carbon $since): ?int
    {
        $low = AnnounceLog::query()->min('id');
        $high = AnnounceLog::query()->max('id');

        if ($low === null || $high === null) {
            return null;
        }

        $low = (int) $low;
        $high = (int) $high;
        $found = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $row = AnnounceLog::query()->where('id', '>=', $middle)->orderBy('id')->first(['id', 'created_at']);

            if ($row === null) {
                $high = $middle - 1;

                continue;
            }

            if ($row->created_at >= $since) {
                $found = (int) $row->id;
                $high = $middle - 1;
            } else {
                $low = (int) $row->id + 1;
            }
        }

        return $found;
    }
}
