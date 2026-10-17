<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;

/**
 * The parts of one announce that every outcome records.
 *
 * @internal
 */
final readonly class AnnounceRequest
{
    public function __construct(
        public UserInterface $user,
        public Torrent $torrent,
        public string $peerId,
        public string $ip,
        public int $port,
        public int $uploaded,
        public int $downloaded,
        public int $left,
        public string $userAgent,
        public string $eventLabel,
    ) {}
}
