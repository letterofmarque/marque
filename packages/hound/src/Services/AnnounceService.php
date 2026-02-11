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
        return TrackerResponse::announce(
            peers: [],
            complete: $this->peerService->getSeeders($torrent->id),
            incomplete: $this->peerService->getLeechers($torrent->id),
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
        // Increment completion counter (the only DB write hound does)
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

        return TrackerResponse::announce(
            peers: $peers,
            complete: $this->peerService->getSeeders($torrent->id),
            incomplete: $this->peerService->getLeechers($torrent->id),
            interval: (int) config('threepio.announce_interval', 1800),
            minInterval: (int) config('threepio.min_announce_interval', 300),
            compact: $useCompact,
        );
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
