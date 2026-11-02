<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Marque\Bloodhound\Contracts\LocationJudge;

/**
 * Locates a peer where its last announce came from.
 *
 * A location is an IP address; for IPv6 it is the /64 (Location). A peer
 * already in the swarm, announcing from where it was in the state it was
 * in, is never refused by a count: the count used to include the announcing
 * peer, so a member at the limit was refused on every announce from every
 * client (F6). It is counted again, without itself, when it moves: to
 * another location, or from seeding to leeching (starting as a seeder and
 * then reporting bytes left was a way round the leeching limit). A second
 * client at a location the member already uses is not a new one. Seeding and
 * leeching are limited separately: the leeching limit is what stops one
 * account being shared to download.
 */
final class DefaultLocationJudge implements LocationJudge
{
    public function judge(LocationQuestion $question): LocationVerdict
    {
        $location = Location::of($question->ip);
        $record = $question->record();

        $moved = $record !== null && Location::of((string) $record['ip']) !== $location;
        $startedLeeching = $record !== null && (int) $record['left'] === 0 && ! $question->seeding();

        if ($record !== null && ! $moved && ! $startedLeeching) {
            return LocationVerdict::pass();
        }

        $places = [];

        foreach ($question->others() as $peer) {
            $place = Location::of((string) $peer['ip']);

            // Another client of the member, in the same state, already here.
            if ($place === $location) {
                return LocationVerdict::pass();
            }

            $places[$place] = true;
        }

        $max = $question->limit();

        if (count($places) < $max) {
            return LocationVerdict::pass();
        }

        $kind = $question->seeding() ? 'seeding' : 'leeching';

        // A client restarted somewhere else under a new peer ID is the usual
        // way to arrive here, and the member can clear the old session rather
        // than wait for it to expire.
        return LocationVerdict::refuse(
            "Location limit reached ({$max} {$kind} locations per torrent); if a client restarted somewhere else, end the other session from your profile",
        );
    }
}
