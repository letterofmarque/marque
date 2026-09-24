<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\TorrentStats;
use Marque\Trove\Support\TrackerStats;

/**
 * Stands in for a tracker. usarrs does not require bloodhound and must not, so
 * the only way to prove usarrs renders what the tracker says — rather than
 * what happens to be in the users table — is a tracker whose answers differ
 * from the table.
 */
class FakeTrackerStats implements TrackerStatsInterface
{
    /** @var array<int|string, TrackerStats> */
    public array $stats = [];

    /** @var array<int|string, string> */
    public array $keys = [];

    /** @var list<int|string> */
    public array $regenerated = [];

    public function statsFor(UserInterface $user): ?TrackerStats
    {
        return $this->stats[$user->getAuthIdentifier()] ?? null;
    }

    public function statsForTorrent(UserInterface $user, Torrent $torrent): ?TorrentStats
    {
        return null;
    }

    public function announceKeyFor(UserInterface $user): ?string
    {
        return $this->keys[$user->getAuthIdentifier()] ?? null;
    }

    public function regenerateAnnounceKey(UserInterface $user): string
    {
        $this->regenerated[] = $user->getAuthIdentifier();

        return $this->keys[$user->getAuthIdentifier()] = 'regenerated'.str_repeat('x', 21);
    }
}
