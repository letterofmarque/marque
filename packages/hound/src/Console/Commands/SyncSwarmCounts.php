<?php

declare(strict_types=1);

namespace Marque\Hound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Models\Torrent;

/**
 * Reconcile the torrents table's seeder/leecher counts against live Redis peer
 * state (#10800).
 *
 * A peer that vanishes (client killed, machine off) never sends `stopped`.
 * threepio only notices an expired peer when something reads it, so a torrent
 * nobody announces on again keeps counting its dead peers. This sweeps each
 * torrent's peers first (cleanupExpiredPeers), then writes the settled counts
 * back. It's the same job as bloodhound:sync-swarm-counts, for the public tracker.
 */
class SyncSwarmCounts extends Command
{
    protected $signature = 'hound:sync-swarm-counts {--chunk=500 : Torrents to load per batch}';

    protected $description = 'Reconcile torrent seeder/leecher counts against live peer state';

    public function handle(PeerService $peers): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $updated = 0;
        $swept = 0;

        Torrent::query()
            ->select(['id', 'seeders', 'leechers'])
            ->chunkById($chunk, function ($torrents) use ($peers, &$updated, &$swept): void {
                foreach ($torrents as $torrent) {
                    $swept += $peers->cleanupExpiredPeers($torrent->id);

                    $seeders = $peers->getSeeders($torrent->id);
                    $leechers = $peers->getLeechers($torrent->id);

                    if ($torrent->seeders === $seeders && $torrent->leechers === $leechers) {
                        continue;
                    }

                    $torrent->forceFill(['seeders' => $seeders, 'leechers' => $leechers])->save();

                    $updated++;
                }
            });

        $this->info("Swept {$swept} expired peer(s); corrected counts on {$updated} torrent(s).");

        return self::SUCCESS;
    }
}
