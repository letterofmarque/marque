<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use DateTimeInterface;

/**
 * One client announcing one torrent, as the tracker last heard from it.
 *
 * Returned by TrackerSessionsInterface. The id is opaque: pass it back to
 * endSession() as it is.
 */
final class TrackerSession
{
    /**
     * @param  string  $client  the client's user agent, as it reported it
     * @param  int  $uploaded  the client's cumulative counter for this session, as last reported
     * @param  int  $downloaded  likewise
     */
    public function __construct(
        public readonly string $id,
        public readonly int $torrentId,
        public readonly ?string $torrentName,
        public readonly string $client,
        public readonly string $ip,
        public readonly bool $seeding,
        public readonly DateTimeInterface $lastAnnounceAt,
        public readonly int $uploaded,
        public readonly int $downloaded,
    ) {}
}
