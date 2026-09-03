<?php

declare(strict_types=1);

namespace Marque\Hound\Services;

use Illuminate\Http\Response;
use Marque\Threepio\Enums\AnnounceEvent;
use Marque\Threepio\Services\PeerService;
use Marque\Threepio\Support\TrackerResponse;
use Marque\Trove\Models\Torrent;

/**
 * Public tracker announce service.
 *
 * Minimal orchestration - no user tracking, no anti-cheat, no stats.
 */
final class AnnounceService
{
    public function __construct(
        private readonly PeerService $peerService,
    ) {}

    /**
     * Handle a public announce request.
     */
    public function handle(
        Torrent $torrent,
        string $peerId,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        ?string $event,
        bool $compact,
        int $numWant,
    ): Response {
        // Check port blacklist
        $blacklisted = config('threepio.blacklisted_ports', []);
        if (in_array($port, $blacklisted, true)) {
            return TrackerResponse::error("Port {$port} is blacklisted");
        }

        // IP limiting
        if (config('hound.ip_limiting.enabled', true)) {
            $maxPerIp = (int) config('hound.ip_limiting.max_per_ip', 50);
            $ipCount = $this->peerService->getIpPeerCount($ip);

            if ($ipCount >= $maxPerIp) {
                return TrackerResponse::error('Too many connections from your IP');
            }
        }

        $isSeeder = $left === 0;
        $eventEnum = AnnounceEvent::tryFrom($event ?? '');

        return match ($eventEnum) {
            AnnounceEvent::Stopped => $this->handleStopped($torrent),
            AnnounceEvent::Completed => $this->handleCompleted($torrent, $peerId, $ip, $port, $uploaded, $downloaded, $left, $isSeeder, $compact, $numWant),
            default => $this->handleRegular($torrent, $peerId, $ip, $port, $uploaded, $downloaded, $left, $isSeeder, $compact, $numWant),
        };
    }

    /**
     * Handle stopped event - peer is leaving the swarm.
     */
    private function handleStopped(Torrent $torrent): Response
    {
        $seeders = $this->peerService->getSeeders($torrent->id);
        $leechers = $this->peerService->getLeechers($torrent->id);

        $this->syncSwarmCounts($torrent, $seeders, $leechers);

        return TrackerResponse::announce(
            peers: [],
            complete: $seeders,
            incomplete: $leechers,
            interval: (int) config('threepio.announce_interval', 1800),
            minInterval: (int) config('threepio.min_announce_interval', 300),
            compact: true,
        );
    }

    /**
     * Handle completed event - transition leecher to seeder in Redis.
     */
    private function handleCompleted(
        Torrent $torrent,
        string $peerId,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        bool $isSeeder,
        bool $compact,
        int $numWant,
    ): Response {
        // Deliberately a blind increment, unlike bloodhound.
        //
        // hound records no user against an announce, so there is nobody to
        // dedupe a completion against — a client restarting mid-download looks
        // exactly like a second person finishing. On a public tracker
        // times_completed therefore means "completed events seen", not
        // "distinct completions", and the two numbers are not comparable
        // across the packages. That is the honest best a tracker with no
        // accountable user can do. See Spec #99.
        $torrent->increment('times_completed');

        return $this->handleRegular($torrent, $peerId, $ip, $port, $uploaded, $downloaded, $left, $isSeeder, $compact, $numWant);
    }

    /**
     * Handle regular announce (started or interval).
     */
    private function handleRegular(
        Torrent $torrent,
        string $peerId,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        bool $isSeeder,
        bool $compact,
        int $numWant,
    ): Response {
        // Upsert peer in Redis (userId 0 = anonymous/public)
        $this->peerService->upsertPeer(
            torrentId: $torrent->id,
            peerId: $peerId,
            userId: 0,
            ip: $ip,
            port: $port,
            uploaded: $uploaded,
            downloaded: $downloaded,
            left: $left,
            userAgent: '',
            isSeeder: $isSeeder,
        );

        // Get peers for response
        $maxPeers = min($numWant, (int) config('threepio.max_peers_per_announce', 50));
        $peers = $this->peerService->getPeersForAnnounce(
            torrentId: $torrent->id,
            excludePeerId: $peerId,
            isSeeder: $isSeeder,
            limit: $maxPeers,
        );

        // Determine response format
        $useCompact = $this->shouldUseCompact($compact);

        $seeders = $this->peerService->getSeeders($torrent->id);
        $leechers = $this->peerService->getLeechers($torrent->id);

        $this->syncSwarmCounts($torrent, $seeders, $leechers);

        return TrackerResponse::announce(
            peers: $peers,
            complete: $seeders,
            incomplete: $leechers,
            interval: (int) config('threepio.announce_interval', 1800),
            minInterval: (int) config('threepio.min_announce_interval', 300),
            compact: $useCompact,
        );
    }

    /**
     * Project the live swarm counts onto the torrent row.
     *
     * Hound otherwise writes to the database only on a completed event, so
     * this is a deliberate addition to the announce path: without it a public
     * catalogue cannot filter or sort on swarm state, because live peers live
     * in Redis. The guard keeps it to an actual change rather than a write per
     * announce — counts are stable between most announces.
     */
    private function syncSwarmCounts(Torrent $torrent, int $seeders, int $leechers): void
    {
        if ($torrent->seeders === $seeders && $torrent->leechers === $leechers) {
            return;
        }

        $torrent->forceFill([
            'seeders' => $seeders,
            'leechers' => $leechers,
        ])->save();
    }

    /**
     * Determine if compact format should be used.
     */
    private function shouldUseCompact(bool $clientSupportsCompact): bool
    {
        $format = config('threepio.peer_response_format', 'auto');

        return match ($format) {
            'compact' => true,
            'dictionary' => false,
            default => $clientSupportsCompact,
        };
    }
}
