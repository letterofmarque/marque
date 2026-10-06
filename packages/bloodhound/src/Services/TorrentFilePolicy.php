<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Marque\Bloodhound\Support\AnnounceRouting;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;

/**
 * What a private tracker needs from .torrent files (#10947).
 *
 * Uploads must be private by default: without the flag, clients share the
 * swarm over DHT and peer exchange, outside the tracker's accounting.
 * Downloads carry the member's own announce URL, built from the same routes
 * config the tracker reads keys with, so the key lands where it's looked for.
 */
final class TorrentFilePolicy implements TorrentFilePolicyInterface
{
    public function __construct(
        private readonly TrackerStatsInterface $stats,
    ) {}

    /**
     * An unrecognised value falls back to require, never to something looser:
     * a typo in config must not quietly open a private tracker to public
     * torrents. Same principle as AnnounceRouting::keySource().
     */
    public function privateFlag(): PrivateFlag
    {
        return PrivateFlag::tryFrom((string) config('bloodhound.uploads.private_flag', 'require'))
            ?? PrivateFlag::Require;
    }

    public function announceUrlFor(?UserInterface $user): ?string
    {
        $key = $user === null ? null : $this->stats->announceKeyFor($user);

        if ($key === null) {
            return null;
        }

        if (AnnounceRouting::keyIsInQuery()) {
            return route('tracker.announce').'?'.http_build_query([AnnounceRouting::keyParameter() => $key]);
        }

        return route('tracker.announce', [AnnounceRouting::keyParameter() => $key]);
    }
}
