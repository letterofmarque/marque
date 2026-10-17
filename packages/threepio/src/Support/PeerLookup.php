<?php

declare(strict_types=1);

namespace Marque\Threepio\Support;

use Marque\Threepio\Enums\BaselineSource;

/**
 * One read of a peer, shared by everything that judges or credits an announce.
 *
 * Anti-cheat and the credit used to read the peer separately, so a speed check
 * could pass against one baseline while the credit was computed against
 * another. A tracker reads this once and hands the same object to both.
 *
 * Two questions live here and they are not the same question. `stored` is
 * whatever sits in the swarm hash under this peer_id, whoever owns it and
 * however old: it decides the swarm bookkeeping (counters, sets). `baseline`
 * is what this member's announce may be diffed against: only this member's
 * record, from the swarm, a retained record, or the durable fallback.
 */
final readonly class PeerLookup
{
    /**
     * @param  array<string, mixed>|null  $baseline  uploaded, downloaded, left, last_action (each may be absent from a recovered one)
     * @param  array<string, mixed>|null  $stored  the swarm hash's record for this peer_id, any owner, any age
     */
    public function __construct(
        public ?array $baseline,
        public BaselineSource $source,
        public ?array $stored,
        public int $peerExpiry,
        public int $readAt,
    ) {}

    /**
     * Is this member's peer in the swarm right now?
     *
     * Only a swarm record counts. A retained or recovered baseline belongs to a
     * session Redis no longer lists, so the peer is new to the swarm even
     * though it has counters to diff against.
     */
    public function isLive(): bool
    {
        return $this->source === BaselineSource::Swarm
            && $this->readAt - (int) ($this->baseline['last_action'] ?? 0) <= $this->peerExpiry;
    }
}
