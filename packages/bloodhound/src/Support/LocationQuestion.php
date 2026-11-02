<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Marque\Threepio\Support\PeerLookup;

/**
 * One announce, put to a LocationJudge.
 */
final readonly class LocationQuestion
{
    /**
     * @param  array<string, array<string, mixed>>  $livePeers  the torrent's live peers, as PeerService::livePeers() returns them
     */
    public function __construct(
        public int $userId,
        public string $peerId,
        public string $ip,
        public int $left,
        public PeerLookup $lookup,
        public array $livePeers,
    ) {}

    public function seeding(): bool
    {
        return $this->left === 0;
    }

    /**
     * This peer's record, when it is in the swarm now; null for a new peer.
     *
     * @return array<string, mixed>|null
     */
    public function record(): ?array
    {
        return $this->lookup->isLive() ? $this->lookup->baseline : null;
    }

    /**
     * The member's other live peers on this torrent in the same state
     * (seeding or leeching) as this announce, keyed by peer ID.
     *
     * @return array<string, array<string, mixed>>
     */
    public function others(): array
    {
        $others = [];

        foreach ($this->livePeers as $peerId => $peer) {
            if ((string) $peerId === $this->peerId || ($peer['user_id'] ?? null) !== $this->userId) {
                continue;
            }

            if ((((int) $peer['left']) === 0) === $this->seeding()) {
                $others[(string) $peerId] = $peer;
            }
        }

        return $others;
    }

    /**
     * The member's limit for this announce's state.
     */
    public function limit(): int
    {
        return $this->seeding()
            ? (int) config('bloodhound.anti_cheat.max_seeding_locations_per_torrent', 3)
            : (int) config('bloodhound.anti_cheat.max_leeching_locations_per_torrent', 1);
    }
}
