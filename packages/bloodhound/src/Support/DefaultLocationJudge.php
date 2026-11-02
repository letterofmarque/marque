<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Marque\Bloodhound\Contracts\LocationJudge;
use Marque\Threepio\Support\PeerAddresses;

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
 *
 * A refusal is ambiguous (#11133) when the peer only moved across address
 * families, within `anti_cheat.ambiguous_window` seconds of its last
 * announce, the place it announced from then is one the member already
 * uses, and it hasn't been seen on another network in the family it now
 * announces over. A new client's refusal is ambiguous when it lands on
 * another client's address in that client's other family, and that client
 * was seen on both within `anti_cheat.dual_stack_gap` seconds. That is a dual-stack client announcing over IPv4 and IPv6 in turn at
 * a house where the member's other client is. It is also exactly what one
 * peer ID announcing from two places looks like, which is why it is only
 * ambiguous: the policy decides, and the ledger detector watches what
 * follows.
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
        $pairedAt = [];

        foreach ($question->others() as $peer) {
            $place = Location::of((string) $peer['ip']);

            // Another client of the member, in the same state, already here.
            if ($place === $location) {
                return LocationVerdict::pass();
            }

            $places[$place] = true;

            if (($other = $this->pairedAddress($question, $peer)) !== null) {
                $pairedAt[Location::of($other)] = true;
            }
        }

        $max = $question->limit();

        if (count($places) < $max) {
            return LocationVerdict::pass();
        }

        $kind = $question->seeding() ? 'seeding' : 'leeching';

        // A client restarted somewhere else under a new peer ID is the usual
        // way to arrive here, and the member can clear the old session rather
        // than wait for it to expire.
        $refusal = "Location limit reached ({$max} {$kind} locations per torrent); if a client restarted somewhere else, end the other session from your profile";

        // A new client at another client's paired address: an IPv4-only
        // laptop starting while the dual-stack desktop's last announce was
        // over IPv6, which it is almost always, its IPv4 one coming seconds
        // before. Refused, it never got a record and stayed locked out.
        $ambiguous = $record === null
            ? isset($pairedAt[$location])
            : $moved && $this->acrossFamilies($question, $record, $places);

        return $ambiguous ? LocationVerdict::ambiguous($refusal) : LocationVerdict::refuse($refusal);
    }

    /**
     * A client's address in the family it didn't last announce over, when
     * it was seen on both families within `anti_cheat.dual_stack_gap`
     * seconds: what a dual-stack client announcing over both endpoints looks
     * like. Grounds for calling a refusal ambiguous, never proof of a place:
     * two announces from two places seconds apart, or an announced address,
     * look the same.
     *
     * @param  array<string, mixed>  $peer
     */
    private function pairedAddress(LocationQuestion $question, array $peer): ?string
    {
        $family = PeerAddresses::family((string) $peer['ip']);
        $other = $family === 4 ? 6 : 4;
        $readAt = $question->lookup->readAt;
        $expiry = $question->lookup->peerExpiry;
        $address = PeerAddresses::of($peer, $readAt, $expiry)["ipv{$other}"];

        $limit = (int) config('bloodhound.anti_cheat.dual_stack_gap', 120);

        // 0 turns pairing off. An announced address is stamped with the
        // moment of its announce, so its gap is always 0, and "<= 0" would
        // leave exactly that route open.
        if ($family === null || $address === null || $limit <= 0) {
            return null;
        }

        $gap = abs((int) ($peer["ipv{$other}_at"] ?? $peer['last_action'] ?? 0) - (int) ($peer["ipv{$family}_at"] ?? $peer['last_action'] ?? 0));

        return $gap <= $limit ? $address : null;
    }

    /**
     * Did the peer just announce over the other family, from a place the
     * member already uses?
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, true>  $places  the other peers' locations
     */
    private function acrossFamilies(LocationQuestion $question, array $record, array $places): bool
    {
        $before = (string) $record['ip'];
        $family = PeerAddresses::family($question->ip);
        $window = (int) config('bloodhound.anti_cheat.ambiguous_window', 3600);

        // A dual-stack machine comes back to a family on its own address. If
        // the peer was last seen on another network in this family, it has
        // moved, and that is no ambiguity.
        $known = PeerAddresses::of($record, $question->lookup->readAt, $question->lookup->peerExpiry)["ipv{$family}"];

        return $family !== PeerAddresses::family($before)
            && $question->lookup->readAt - (int) $record['last_action'] <= $window
            && isset($places[Location::of($before)])
            && ($known === null || Location::of($known) === Location::of($question->ip));
    }
}
