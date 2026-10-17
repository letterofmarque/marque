<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Marque\Threepio\Support\PeerLookup;

/**
 * An accepted announce's baseline and anti-cheat's verdict on it: what the
 * ledger row and the swarm write are both made from.
 *
 * @internal
 */
final readonly class AnnounceCredit
{
    /**
     * @param  array{passed: bool, reason: ?string, forfeit_upload: bool, forfeit_download: bool}  $check
     */
    public function __construct(
        public PeerLookup $lookup,
        public array $check,
    ) {}
}
