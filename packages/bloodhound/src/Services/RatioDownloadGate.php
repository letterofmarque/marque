<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Marque\Trove\Contracts\DownloadGateInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;

/**
 * trove's download gate, answered by the ratio rule (#10732): a member whose
 * downloading is paused gets no .torrent for a torrent they aren't already
 * in. The same rule, and the same words, as the announce path's refusal.
 */
class RatioDownloadGate implements DownloadGateInterface
{
    public function __construct(private readonly RatioEnforcer $ratio) {}

    public function refusalFor(?UserInterface $user, Torrent $torrent): ?string
    {
        return $user === null ? null : $this->ratio->leechRefusal($user->getAuthIdentifier(), (int) $torrent->getKey());
    }
}
