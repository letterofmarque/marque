<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Response;
use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Jobs\LogAnnounce;
use Marque\Bloodhound\Jobs\UpdateUserStats;
use Marque\Threepio\Enums\AnnounceEvent;
use Marque\Threepio\Services\PeerService;
use Marque\Threepio\Support\TrackerResponse;
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
            // Still logged (Spec #98) — a rejected announce is itself part
            // of the history worth keeping, not just the ones that passed.
            // No PeerService::upsertPeer() ran, so there's no real delta to
            // report here; the point of this row is that the announce
            // happened and was rejected, and why.
            $this->logAnnounce(
                user: $user,
                torrent: $torrent,
                peerId: $peerId,
                eventLabel: $this->eventLabel($event),
                ip: $ip,
                port: $port,
                userAgent: $userAgent,
                uploaded: $uploaded,
                downloaded: $downloaded,
                left: $left,
                uploadDelta: 0,
                downloadDelta: 0,
                antiCheatCheck: $antiCheatCheck,
            );

            return TrackerResponse::error($antiCheatCheck['reason'] ?? 'Request rejected');
        }

        // Determine if this is a seeder
        $isSeeder = $left === 0;

        // Parse event
        $eventEnum = AnnounceEvent::tryFrom($event ?? '');

        // Handle the event
        return match ($eventEnum) {
            AnnounceEvent::Stopped => $this->handleStopped(
                $user, $torrent, $peerId, $ip, $port, $uploaded, $downloaded, $left, $userAgent, $antiCheatCheck
            ),
            AnnounceEvent::Completed => $this->handleCompleted(
                $user, $torrent, $peerId, $ip, $port,
                $uploaded, $downloaded, $left, $userAgent, $isSeeder, $compact, $numWant, $antiCheatCheck
            ),
            default => $this->handleRegular(
                $user, $torrent, $peerId, $ip, $port,
                $uploaded, $downloaded, $left, $userAgent, $isSeeder, $compact, $numWant, $antiCheatCheck,
                eventLabel: $eventEnum?->value ?? 'regular',
            ),
        };
    }

    /**
     * Handle stopped event - peer is leaving the swarm.
     */
    private function handleStopped(
        UserInterface $user,
        Torrent $torrent,
        string $peerId,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        string $userAgent,
        array $antiCheatCheck,
    ): Response {
        $this->peerService->removePeer($torrent->id, $peerId);

        // No further delta to report — the peer's last known upload/download
        // was already captured on its prior announce. This row's value is
        // recording that the peer left, and when.
        $this->logAnnounce(
            user: $user,
            torrent: $torrent,
            peerId: $peerId,
            eventLabel: 'stopped',
            ip: $ip,
            port: $port,
            userAgent: $userAgent,
            uploaded: $uploaded,
            downloaded: $downloaded,
            left: $left,
            uploadDelta: 0,
            downloadDelta: 0,
            antiCheatCheck: $antiCheatCheck,
        );

        // The stopped path is where a torrent actually goes dead, so the
        // projection matters most here — this is the decrement the old
        // `visible` flag never had.
        $seeders = $this->peerService->getSeeders($torrent->id);
        $leechers = $this->peerService->getLeechers($torrent->id);

        $this->syncSwarmCounts($torrent, $seeders, $leechers);

        // Even on stopped, return a valid response
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
        array $antiCheatCheck,
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

        // Process as a regular announce, but logged as 'completed' — not
        // delegated silently, or the announce_log row would say 'regular'
        // for what a client and the operator both consider a completion.
        return $this->handleRegular(
            $user, $torrent, $peerId, $ip, $port,
            $uploaded, $downloaded, $left, $userAgent, $isSeeder, $compact, $numWant, $antiCheatCheck,
            eventLabel: 'completed',
        );
    }

    /**
     * Handle regular announce (started or interval) — also the shared tail
     * end of handleCompleted(), distinguished by $eventLabel.
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
        array $antiCheatCheck,
        string $eventLabel = 'regular',
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

        $this->logAnnounce(
            user: $user,
            torrent: $torrent,
            peerId: $peerId,
            eventLabel: $eventLabel,
            ip: $ip,
            port: $port,
            userAgent: $userAgent,
            uploaded: $uploaded,
            downloaded: $downloaded,
            left: $left,
            uploadDelta: $result['upload_delta'],
            downloadDelta: $result['download_delta'],
            antiCheatCheck: $antiCheatCheck,
        );

        // Queue user stats update if there are deltas
        if ($result['upload_delta'] > 0 || $result['download_delta'] > 0) {
            $this->queueStatsUpdate(
                userId: $user->getAuthIdentifier(),
                uploadDelta: $result['upload_delta'],
                downloadDelta: $result['download_delta'],
            );
        }

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

        // The response needs these anyway, so projecting them onto the torrent
        // costs nothing extra. Redis stays the source of truth for live peers;
        // the columns exist so the catalogue can filter and sort on swarm
        // state, which it cannot do against Redis.
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
     * Skips the write when nothing changed — announces arrive every few
     * minutes per peer and the counts are stable most of the time, so an
     * unconditional update would add a pointless write to the hottest path in
     * the tracker.
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
     * Map the raw request 'event' param to the label announce_log records.
     * 'started' is a real AnnounceEvent case; an empty/missing event (a
     * regular interval announce) has no case, hence the 'regular' fallback.
     */
    private function eventLabel(?string $event): string
    {
        return AnnounceEvent::tryFrom($event ?? '')?->value ?? 'regular';
    }

    /**
     * Dispatch a LogAnnounce job (Spec #98), only when the feature is on —
     * no dispatch call at all when disabled, not a job that no-ops.
     */
    private function logAnnounce(
        UserInterface $user,
        Torrent $torrent,
        string $peerId,
        string $eventLabel,
        string $ip,
        int $port,
        string $userAgent,
        int $uploaded,
        int $downloaded,
        int $left,
        int $uploadDelta,
        int $downloadDelta,
        array $antiCheatCheck,
    ): void {
        if (! config('bloodhound.announce_log.enabled', false)) {
            return;
        }

        $job = new LogAnnounce(
            userId: $user->getAuthIdentifier(),
            torrentId: $torrent->id,
            peerId: $peerId,
            event: $eventLabel,
            ip: $ip,
            port: $port,
            userAgent: $userAgent,
            uploaded: $uploaded,
            downloaded: $downloaded,
            left: $left,
            uploadDelta: $uploadDelta,
            downloadDelta: $downloadDelta,
            antiCheatFlagged: ! $antiCheatCheck['passed'],
            antiCheatReason: $antiCheatCheck['reason'] ?? null,
        );

        // Same queue-or-sync choice as queueStatsUpdate() — reuses
        // bloodhound.queue.*, not a second queue config for this feature.
        if (config('bloodhound.queue.enabled', true)) {
            app(Dispatcher::class)->dispatch(
                $job->onConnection(config('bloodhound.queue.connection'))
                    ->onQueue(config('bloodhound.queue.queue', 'tracker'))
            );
        } else {
            app(Dispatcher::class)->dispatchSync($job);
        }
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
            default => $clientSupportsCompact, // 'auto'
        };
    }
}
