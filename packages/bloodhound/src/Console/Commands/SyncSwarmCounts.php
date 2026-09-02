<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Models\Torrent;

/**
 * Reconcile the torrents table's seeder/leecher counts against live Redis peer
 * state.
 *
 * This command is the reason the counts can be trusted at all, and the reason
 * the `visible` flag it replaces could not be. The announce path keeps the
 * columns fresh while peers are announcing, but a peer that simply vanishes —
 * client killed, machine off, network gone — never sends `stopped`. Its Redis
 * entry expires silently, and nothing announces afterwards to correct the row.
 * A torrent whose last seeder disappeared that way would otherwise sit at
 * seeders=1 forever, which is exactly the rot that made `visible` meaningless:
 * a write path with no invalidation path.
 *
 * Peer expiry in Redis is lazy — PeerService only notices an expired peer when
 * something reads it — so this sweeps each torrent's peer hash first
 * (cleanupExpiredPeers), forcing the counters to settle, then writes the
 * settled values back.
 */
class SyncSwarmCounts extends Command
{
    protected $signature = 'bloodhound:sync-swarm-counts {--chunk=500 : Torrents to load per batch}';

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
                    // Force lazy expiry to resolve before reading the counters,
                    // or a torrent nobody has announced on since its peers died
                    // keeps reporting them.
                    $swept += $peers->cleanupExpiredPeers($torrent->id);

                    $seeders = $peers->getSeeders($torrent->id);
                    $leechers = $peers->getLeechers($torrent->id);

                    if ($torrent->seeders === $seeders && $torrent->leechers === $leechers) {
                        continue;
                    }

                    $torrent->forceFill([
                        'seeders' => $seeders,
                        'leechers' => $leechers,
                    ])->save();

                    $updated++;
                }
            });

        $this->info("Swept {$swept} expired peer(s); corrected counts on {$updated} torrent(s).");

        return self::SUCCESS;
    }
}
