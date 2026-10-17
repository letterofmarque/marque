<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Carbon\Carbon;
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
        private readonly RedisDurability $durability,
    ) {}

    /**
     * Handle an announce request.
     *
     * Every outcome writes one ledger row, and the row comes first: a refusal
     * (client validation or anti-cheat) is recorded and touches nothing in
     * Redis; an accepted announce is credited against the peer's baseline,
     * recorded, and only then written to the swarm. A failed insert therefore
     * leaves the baseline where it was, and the client's retry is credited in
     * full.
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
        // The baseline every credit is measured against lives in Redis. A
        // Redis that can forget it is refused in production, before anything
        // is read or written (Job #193).
        if (! $this->durability->allowsAnnounces()) {
            return TrackerResponse::error('Tracker storage is not configured for durability; the operator has been notified');
        }

        $announce = new AnnounceRequest($user, $torrent, $peerId, $ip, $port, $uploaded, $downloaded, $left, $userAgent, $this->eventLabel($event));

        // A refused client is still an announce from a known member on a known
        // torrent, so it is recorded like any other refusal.
        $clientCheck = $this->clientValidation->validate($peerId);
        if (! $clientCheck['valid']) {
            $reason = $clientCheck['reason'] ?? 'Client not allowed';
            $this->writeRefusal($announce, $reason);

            return TrackerResponse::error($reason);
        }

        // One read of the peer, for anti-cheat and the credit alike, and one
        // of the torrent's live peers, for the location limits and the
        // response's peer list.
        $lookup = $this->peerService->lookup($torrent->id, $peerId, $user->getAuthIdentifier());
        $eventEnum = AnnounceEvent::tryFrom($event ?? '');
        $livePeers = $eventEnum === AnnounceEvent::Stopped ? [] : $this->peerService->livePeers($torrent->id);

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
            event: $eventEnum?->value,
            lookup: $lookup,
            livePeers: $livePeers,
        );

        if (! $antiCheatCheck['passed']) {
            // Recorded (Spec #98): a rejected announce is part of the history
            // worth keeping. It credits nothing, and Redis is untouched, so the
            // next accepted announce is measured from the last accepted one.
            $reason = $antiCheatCheck['reason'] ?? 'Request rejected';
            $this->writeRefusal($announce, $reason);

            return TrackerResponse::error($reason);
        }

        $credit = new AnnounceCredit($lookup, $antiCheatCheck);

        return match ($eventEnum) {
            AnnounceEvent::Stopped => $this->handleStopped($announce, $credit),
            AnnounceEvent::Completed => $this->handleCompleted($announce, $credit, $livePeers, $compact, $numWant),
            default => $this->handleRegular($announce, $credit, $livePeers, $compact, $numWant),
        };
    }

    /**
     * Handle stopped event - peer is leaving the swarm.
     *
     * BEP 3's `stopped` carries the client's final counters, so whatever moved
     * since its last announce is credited here, bytes and time, against the
     * record about to be removed. The session ends: the record is not kept.
     */
    private function handleStopped(AnnounceRequest $announce, AnnounceCredit $credit): Response
    {
        $this->writeCredit($announce, $credit);

        $this->peerService->removePeer($announce->torrent->id, $announce->peerId);

        // The stopped path is where a torrent actually goes dead, so the
        // projection matters most here — this is the decrement the old
        // `visible` flag never had.
        $seeders = $this->peerService->getSeeders($announce->torrent->id);
        $leechers = $this->peerService->getLeechers($announce->torrent->id);

        $this->syncSwarmCounts($announce->torrent, $seeders, $leechers);

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
    /**
     * @param  array<string, array<string, mixed>>  $livePeers
     */
    private function handleCompleted(AnnounceRequest $announce, AnnounceCredit $credit, array $livePeers, bool $compact, int $numWant): Response
    {
        event(new TorrentCompleted(
            userId: $announce->user->getAuthIdentifier(),
            torrentId: $announce->torrent->id,
            ip: $announce->ip,
            userAgent: $announce->userAgent,
        ));

        // The torrent's completion count is bumped by RecordCompletion,
        // which only counts a completion the user's own row counted — so a
        // client restarting mid-download no longer inflates it. See Spec #99.
        //
        // Credited as a regular announce; the row says 'completed' because the
        // request label does.
        return $this->handleRegular($announce, $credit, $livePeers, $compact, $numWant);
    }

    /**
     * Handle regular announce (started or interval) — also the shared tail
     * end of handleCompleted().
     */
    /**
     * @param  array<string, array<string, mixed>>  $livePeers
     */
    private function handleRegular(AnnounceRequest $announce, AnnounceCredit $credit, array $livePeers, bool $compact, int $numWant): Response
    {
        $isSeeder = $announce->left === 0;
        $torrentId = $announce->torrent->id;

        // The ledger row first, then Redis. No stats dispatch: the aggregator
        // folds this row into the totals, and sending the same number through a
        // queue as well would put a second, losable copy in flight (Spec #99).
        $this->writeCredit($announce, $credit);

        $this->peerService->upsertPeer(
            torrentId: $torrentId,
            peerId: $announce->peerId,
            userId: $announce->user->getAuthIdentifier(),
            ip: $announce->ip,
            port: $announce->port,
            uploaded: $announce->uploaded,
            downloaded: $announce->downloaded,
            left: $announce->left,
            userAgent: $announce->userAgent,
            isSeeder: $isSeeder,
            lookup: $credit->lookup,
        );

        $maxPeers = min($numWant, (int) config('threepio.max_peers_per_announce', 50));
        $peers = $this->peerService->getPeersForAnnounce(
            torrentId: $torrentId,
            excludePeerId: $announce->peerId,
            isSeeder: $isSeeder,
            limit: $maxPeers,
            livePeers: $livePeers,
        );

        $useCompact = $this->shouldUseCompact($compact);

        // The response needs these anyway, so projecting them onto the torrent
        // costs nothing extra. Redis stays the source of truth for live peers;
        // the columns exist so the catalogue can filter and sort on swarm
        // state, which it cannot do against Redis.
        $seeders = $this->peerService->getSeeders($torrentId);
        $leechers = $this->peerService->getLeechers($torrentId);

        $this->syncSwarmCounts($announce->torrent, $seeders, $leechers);

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
     * Record a refused announce: no credit, no baseline, flagged with why.
     */
    private function writeRefusal(AnnounceRequest $announce, string $reason): void
    {
        $this->writeRow($announce, [
            'upload_delta' => 0,
            'download_delta' => 0,
            'seed_seconds' => 0,
            'leech_seconds' => 0,
            'prior_up' => null,
            'prior_down' => null,
            'prior_at' => null,
            'anti_cheat_flagged' => true,
            'anti_cheat_reason' => $reason,
        ]);
    }

    /**
     * Record an accepted announce and what it is credited.
     *
     * The interval goes to the state the peer was in when it began (Spec
     * #121): the announce that reports completion closes a leeching interval.
     * A direction anti-cheat forfeited is recorded as 0, with the row flagged
     * and the reason saying how much went uncredited.
     *
     * Stamped with the moment the credit was measured, which is also the
     * peer's new last announce in Redis: the next row's prior_at is then this
     * row's created_at exactly, and the audit can check every interval.
     */
    private function writeCredit(AnnounceRequest $announce, AnnounceCredit $announceCredit): void
    {
        $credit = $announceCredit->lookup->credit($announce->uploaded, $announce->downloaded);
        $check = $announceCredit->check;
        $seeding = ($credit['prior_left'] ?? $announce->left) === 0;
        $forfeited = $check['forfeit_upload'] || $check['forfeit_download'];

        $this->writeRow($announce, [
            'upload_delta' => $check['forfeit_upload'] ? 0 : $credit['upload_delta'],
            'download_delta' => $check['forfeit_download'] ? 0 : $credit['download_delta'],
            'seed_seconds' => $seeding ? $credit['interval_seconds'] : 0,
            'leech_seconds' => $seeding ? 0 : $credit['interval_seconds'],
            'prior_up' => $credit['prior_up'],
            'prior_down' => $credit['prior_down'],
            'prior_at' => $credit['prior_at'] !== null ? $this->at($credit['prior_at']) : null,
            'anti_cheat_flagged' => $forfeited,
            'anti_cheat_reason' => $forfeited ? $check['reason'] : null,
            'created_at' => $this->at($credit['measured_at']),
        ]);
    }

    /**
     * A Unix timestamp in the app's timezone, the one created_at is written in.
     */
    private function at(int $timestamp): Carbon
    {
        return Carbon::now()->setTimestamp($timestamp);
    }

    /**
     * Write the ledger row for this announce (Spec #99).
     *
     * Synchronous and inline: this is the durable record the tracker's whole
     * accounting is summed from, so it must exist before the response does.
     *
     * @param  array<string, mixed>  $outcome
     */
    private function writeRow(AnnounceRequest $announce, array $outcome): void
    {
        if (! config('bloodhound.announce_log.enabled', true)) {
            return;
        }

        // Written synchronously, before Redis and before the response.
        //
        // This row is the durable record of what the tracker credited, and
        // everything else — user totals, per-torrent totals, the reconciliation
        // that proves them right — is a projection summed from it. Dispatching
        // it to a queue would mean the only copy of a byte count lived in a job
        // payload, and a lost job would be a lost credit with nothing left to
        // re-derive it from. See Spec #99.
        (new AnnounceLog)->forceFill([
            'user_id' => $announce->user->getAuthIdentifier(),
            'torrent_id' => $announce->torrent->id,
            'peer_id' => $announce->peerId,
            'event' => $announce->eventLabel,
            'ip' => $announce->ip,
            'port' => $announce->port,
            'user_agent' => $announce->userAgent,
            'uploaded' => $announce->uploaded,
            'downloaded' => $announce->downloaded,
            'left' => $announce->left,
        ] + $outcome)->save();
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
