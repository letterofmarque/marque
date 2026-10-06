<?php

declare(strict_types=1);

namespace Marque\Hound\Services;

use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;

/**
 * What a public tracker needs from .torrent files (#10947).
 *
 * Downloads point at the open announce URL, the same for everyone. Uploads
 * must not be private by default: the flag stops clients using DHT and peer
 * exchange, which a public swarm relies on.
 */
final class TorrentFilePolicy implements TorrentFilePolicyInterface
{
    /**
     * An unrecognised value falls back to the default, disallow.
     */
    public function privateFlag(): PrivateFlag
    {
        return PrivateFlag::tryFrom((string) config('hound.uploads.private_flag', 'disallow'))
            ?? PrivateFlag::Disallow;
    }

    public function announceUrlFor(?UserInterface $user): string
    {
        return route('tracker.announce');
    }
}
