<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Http\Response;
use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Models\AnnounceLog;
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
            priorUp: $result['prior_up'],
            priorDown: $result['prior_down'],
        );

        // No stats dispatch here any more. The deltas were just written to
        // the ledger above, and bloodhound:aggregate-ledger folds them into
        // user and per-torrent totals from there. Sending them through a queue
        // as well would put a second, losable copy of the same number in
        // flight — which is the failure this design removes. See Spec #99.

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
     * Map the raw request 'event' param to the label announce_log records.
     * 'started' is a real AnnounceEvent case; an empty/missing event (a
     * regular interval announce) has no case, hence the 'regular' fallback.
     */
    private function eventLabel(?string $event): string
    {
        return AnnounceEvent::tryFrom($event ?? '')?->value ?? 'regular';
    }

    /**
     * Write the ledger row for this announce (Spec #99).
     *
     * Synchronous and inline: this is the durable record the tracker's whole
     * accounting is rebuilt from, so it must exist before the response does.
     * Was a queued job under Spec #98, when the table was an optional
     * investigative log rather than the source of truth.
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
        ?int $priorUp = null,
        ?int $priorDown = null,
    ): void {
        if (! config('bloodhound.announce_log.enabled', true)) {
            return;
        }

        // Written synchronously, before the response goes back to the client.
        //
        // This row is the durable record of what the tracker credited, and
        // everything else — user totals, per-torrent totals, the reconciliation
        // that proves them right — is a projection rebuilt from it. Dispatching
        // it to a queue would mean the only copy of a byte count lived in a job
        // payload, and a lost job would be a lost credit with nothing left to
        // re-derive it from. See Spec #99.
        //
        // The cost is one insert on the announce path, which at tracker volumes
        // is a fraction of the request. That is the trade, made deliberately.
        AnnounceLog::create([
            'user_id' => $user->getAuthIdentifier(),
            'torrent_id' => $torrent->id,
            'peer_id' => $peerId,
            'event' => $eventLabel,
            'ip' => $ip,
            'port' => $port,
            'user_agent' => $userAgent,
            'uploaded' => $uploaded,
            'downloaded' => $downloaded,
            'left' => $left,
            'upload_delta' => $uploadDelta,
            'download_delta' => $downloadDelta,
            'prior_up' => $priorUp,
            'prior_down' => $priorDown,
            'anti_cheat_flagged' => ! $antiCheatCheck['passed'],
            'anti_cheat_reason' => $antiCheatCheck['reason'] ?? null,
        ]);
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
