<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Http\Response;
use Marque\Bloodhound\Enums\AnnounceEvent;
use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Jobs\UpdateUserStats;
use Marque\Bloodhound\Support\TrackerResponse;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;

/**
 * Core announce handling service.
 */
final class AnnounceService
{
    public function __construct(
        private readonly PeerService $peerService,
        private readonly ClientValidationService $clientValidation,
        private readonly AntiCheatService $antiCheat,
    ) {}

    /**
     * Handle an announce request.
     */
    public function handle(
        UserInterface $user,
        Torrent $torrent,
        string $peerId,
        string $infoHash,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        ?string $event,
        string $userAgent,
        bool $compact,
        int $numWant,
    ): Response {
        // Validate client
        $clientCheck = $this->clientValidation->validate($peerId);
        if (! $clientCheck['valid']) {
            return TrackerResponse::error($clientCheck['reason'] ?? 'Client not allowed');
        }

        // Run anti-cheat checks
        $antiCheatCheck = $this->antiCheat->check(
            torrentId: $torrent->id,
            userId: $user->getAuthIdentifier(),
            peerId: $peerId,
            ip: $ip,
            port: $port,
            uploaded: $uploaded,
            downloaded: $downloaded,
            left: $left,
            torrentSize: $torrent->size,
        );

        if (! $antiCheatCheck['passed']) {
            return TrackerResponse::error($antiCheatCheck['reason'] ?? 'Request rejected');
        }

        // Determine if this is a seeder
        $isSeeder = $left === 0;

        // Parse event
        $eventEnum = AnnounceEvent::tryFrom($event ?? '');

        // Handle the event
        return match ($eventEnum) {
            AnnounceEvent::Stopped => $this->handleStopped($torrent, $peerId),
            AnnounceEvent::Completed => $this->handleCompleted(
                $user, $torrent, $peerId, $ip, $port,
                $uploaded, $downloaded, $left, $userAgent, $isSeeder, $compact, $numWant
            ),
            default => $this->handleRegular(
                $user, $torrent, $peerId, $ip, $port,
                $uploaded, $downloaded, $left, $userAgent, $isSeeder, $compact, $numWant
            ),
        };
    }

    /**
     * Handle stopped event - peer is leaving the swarm.
     */
    private function handleStopped(Torrent $torrent, string $peerId): Response
    {
        $removedPeer = $this->peerService->removePeer($torrent->id, $peerId);

        // Even on stopped, return a valid response
        return TrackerResponse::announce(
            peers: [],
            complete: $this->peerService->getSeeders($torrent->id),
            incomplete: $this->peerService->getLeechers($torrent->id),
            interval: (int) config('bloodhound.announce_interval', 1800),
            minInterval: (int) config('bloodhound.min_announce_interval', 300),
            compact: true,
        );
    }

    /**
     * Handle completed event - peer finished downloading.
     */
    private function handleCompleted(
        UserInterface $user,
        Torrent $torrent,
        string $peerId,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        string $userAgent,
        bool $isSeeder,
        bool $compact,
        int $numWant,
    ): Response {
        // Record the snatch/completion
        event(new TorrentCompleted(
            userId: $user->getAuthIdentifier(),
            torrentId: $torrent->id,
            ip: $ip,
            userAgent: $userAgent,
        ));

        // Update the torrent's completion count
        $torrent->increment('times_completed');

        // Process as a regular announce
        return $this->handleRegular(
            $user, $torrent, $peerId, $ip, $port,
            $uploaded, $downloaded, $left, $userAgent, $isSeeder, $compact, $numWant
        );
    }

    /**
     * Handle regular announce (started or interval).
     */
    private function handleRegular(
        UserInterface $user,
        Torrent $torrent,
        string $peerId,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        string $userAgent,
        bool $isSeeder,
        bool $compact,
        int $numWant,
    ): Response {
        // Upsert peer and get stats deltas
        $result = $this->peerService->upsertPeer(
            torrentId: $torrent->id,
            peerId: $peerId,
            userId: $user->getAuthIdentifier(),
            ip: $ip,
            port: $port,
            uploaded: $uploaded,
            downloaded: $downloaded,
            left: $left,
            userAgent: $userAgent,
            isSeeder: $isSeeder,
        );

        // Queue user stats update if there are deltas
        if ($result['upload_delta'] > 0 || $result['download_delta'] > 0) {
            $this->queueStatsUpdate(
                userId: $user->getAuthIdentifier(),
                uploadDelta: $result['upload_delta'],
                downloadDelta: $result['download_delta'],
            );
        }

        // Update torrent visibility if seeder
        if ($isSeeder && ! $torrent->visible) {
            $torrent->update(['visible' => true]);
        }

        // Get peers for response
        $maxPeers = min($numWant, (int) config('bloodhound.max_peers_per_announce', 50));
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
            interval: (int) config('bloodhound.announce_interval', 1800),
            minInterval: (int) config('bloodhound.min_announce_interval', 300),
            compact: $useCompact,
        );
    }

    /**
     * Queue a stats update for the user.
     */
    private function queueStatsUpdate(int $userId, int $uploadDelta, int $downloadDelta): void
    {
        if (config('bloodhound.queue.enabled', true)) {
            UpdateUserStats::dispatch($userId, $uploadDelta, $downloadDelta)
                ->onConnection(config('bloodhound.queue.connection'))
                ->onQueue(config('bloodhound.queue.queue', 'tracker'));
        } else {
            // Immediate update if queue disabled
            UpdateUserStats::dispatchSync($userId, $uploadDelta, $downloadDelta);
        }
    }

    /**
     * Determine if compact format should be used.
     */
    private function shouldUseCompact(bool $clientSupportsCompact): bool
    {
        $format = config('bloodhound.peer_response_format', 'auto');

        return match ($format) {
            'compact' => true,
            'dictionary' => false,
            default => $clientSupportsCompact, // 'auto'
        };
    }
}
