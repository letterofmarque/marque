<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Contracts\LocationJudge;
use Marque\Bloodhound\Contracts\SeedboxRegistry;
use Marque\Bloodhound\Events\CheatDetected;
use Marque\Bloodhound\Support\LocationQuestion;
use Marque\Bloodhound\Support\LocationVerdict;
use Marque\Threepio\Services\PeerService;
use Marque\Threepio\Support\PeerLookup;

/**
 * Anti-cheat detection service for the tracker.
 *
 * Judges an announce against the same peer record the credit uses (one
 * PeerLookup, read once), so nothing is checked against one baseline and
 * credited against another. Refuses only what a legitimate client never does:
 *
 * - a blacklisted or invalid port;
 * - a regular announce sooner than the minimum gap after the peer's last
 *   accepted one (protocol events are never refused for timing);
 * - a NEW location past the member's seeding or leeching limit on the
 *   torrent, or a new client past the member's limit on it (a peer already
 *   in the swarm is never refused by a count, and how many torrents a member
 *   seeds is never limited).
 *
 * A claimed speed over the cap is not refused: those bytes are forfeited,
 * the baseline moves on, and the rest of the announce is credited. Data and
 * swarm anomalies are reported and never refused.
 */
final class AntiCheatService
{
    /**
     * How an ambiguous location's ledger row reason begins, under `flag`.
     * The detector finds them by it.
     */
    public const AMBIGUOUS_LOCATION = 'Ambiguous location';

    public function __construct(
        private readonly PeerService $peerService,
        private readonly SeedboxRegistry $seedboxes,
        private readonly LocationJudge $locations,
    ) {}

    /**
     * Run all anti-cheat checks on an announce request.
     *
     * `forfeit_upload` / `forfeit_download` mark a direction whose bytes are
     * not credited; `reason` says why, for the ledger row. `flag` is a reason
     * to flag the row of an announce that passed and is credited in full (an
     * ambiguous location under `flag`).
     *
     * @param  array<string, array<string, mixed>>  $livePeers  the torrent's live peers, as PeerService::livePeers() returns them
     * @return array{passed: bool, reason: ?string, forfeit_upload: bool, forfeit_download: bool, flag: ?string}
     */
    public function check(
        int $torrentId,
        int $userId,
        string $peerId,
        string $ip,
        int $port,
        int $uploaded,
        int $downloaded,
        int $left,
        int $torrentSize,
        ?string $event,
        PeerLookup $lookup,
        array $livePeers,
    ): array {
        $pass = ['passed' => true, 'reason' => null, 'forfeit_upload' => false, 'forfeit_download' => false, 'flag' => null];

        if (! config('bloodhound.anti_cheat.enabled', true)) {
            return $pass;
        }

        $portCheck = $this->checkPort($port);
        if (! $portCheck['passed']) {
            return $portCheck + $pass;
        }

        $gapCheck = $this->checkAnnounceGap($event, $ip, $lookup);
        if (! $gapCheck['passed']) {
            return $gapCheck + $pass;
        }

        $limitCheck = $this->checkLocationLimits($userId, $peerId, $ip, $left, $event, $lookup, $livePeers);
        if (! $limitCheck['passed']) {
            return $limitCheck + $pass;
        }

        if (($limitCheck['flag'] ?? null) !== null) {
            $pass['flag'] = $limitCheck['flag'];

            // Every such row is flagged, for the detector. The event fires
            // once a day per peer: a dual-stack household is ambiguous on
            // every announce over its second family, and an event each time
            // would bury everything else staff are shown.
            $once = $this->prefix().'ambiguous:'.$torrentId.':'.bin2hex($peerId);

            if ($this->redis()->set($once, 1, 'EX', 86_400, 'NX')) {
                $this->dispatchCheatEvent('location_ambiguous', $userId, $torrentId, $limitCheck['flag']);
            }
        }

        // More left than the torrent holds is impossible, but trove's size
        // and a hybrid torrent's padding can disagree at the margin, and a
        // false refusal locks a member out of the swarm. Reported, not refused.
        if ($left > $torrentSize) {
            $this->dispatchCheatEvent('data_anomaly', $userId, $torrentId, "Reported {$left} bytes left of a {$torrentSize}-byte torrent");
        }

        $speed = $this->checkSpeed($userId, $ip, $uploaded, $downloaded, $lookup);
        if ($speed['reason'] !== null) {
            $this->dispatchCheatEvent('speed_violation', $userId, $torrentId, $speed['reason']);
        }

        // Swarm consistency is expensive, so it is sampled; never refused.
        if (mt_rand(1, 100) <= 5) {
            $swarmCheck = $this->checkSwarmConsistency($torrentId, $torrentSize);
            if (! $swarmCheck['passed']) {
                $this->dispatchCheatEvent('swarm_anomaly', null, $torrentId, $swarmCheck['reason']);
            }
        }

        return $speed + $pass;
    }

    /**
     * Check if port is blacklisted.
     */
    public function checkPort(int $port): array
    {
        $blacklisted = config('threepio.blacklisted_ports', []);

        if (in_array($port, $blacklisted, true)) {
            return [
                'passed' => false,
                'reason' => "Port {$port} is blacklisted",
            ];
        }

        // Check valid range
        if ($port <= 0 || $port > 65535) {
            return [
                'passed' => false,
                'reason' => 'Invalid port number',
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Refuse a regular announce sooner than the minimum gap after the last
     * accepted one (a hammer guard).
     *
     * Only a regular announce: started, completed and stopped are the
     * client reporting something that happened, and refusing them loses a
     * completion or leaves a ghost peer in the swarm (F7). Measured from the
     * peer's record, so a refusal moves nothing: a client retrying every 30
     * seconds is accepted every minute rather than locked out.
     */
    public function checkAnnounceGap(?string $event, string $ip, PeerLookup $lookup): array
    {
        // From the same address only. A dual-stack client announces over IPv4
        // and IPv6 seconds apart with one peer ID; the second is not
        // hammering, and is credited only what is new since the first.
        if ($event !== null || ! $lookup->isLive() || (string) ($lookup->baseline['ip'] ?? '') !== $ip) {
            return ['passed' => true, 'reason' => null];
        }

        $minGap = (int) config('bloodhound.anti_cheat.min_announce_gap', 60);

        if ($lookup->readAt - (int) $lookup->baseline['last_action'] < $minGap) {
            return [
                'passed' => false,
                'reason' => "Announcing too frequently (wait {$minGap}s between announces)",
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Refuse a new location past the member's limit on this torrent, or a
     * new client past their limit on it.
     *
     * Where a peer is, and whether that keeps the member within their limit,
     * is the LocationJudge's question (DefaultLocationJudge unless the app
     * binds its own). The client count is asked here.
     *
     * An ambiguous verdict goes to `anti_cheat.ambiguous_locations`: `refuse`
     * refuses it, `allow` passes it, and `flag` (the default, and what any
     * other value means) passes it with a `flag` reason for the ledger row.
     *
     * @param  array<string, array<string, mixed>>  $livePeers
     * @return array{passed: bool, reason: ?string, flag?: ?string}
     */
    public function checkLocationLimits(int $userId, string $peerId, string $ip, int $left, ?string $event, PeerLookup $lookup, array $livePeers): array
    {
        if ($event === 'stopped') {
            return ['passed' => true, 'reason' => null];
        }

        $verdict = $this->locations->judge(new LocationQuestion($userId, $peerId, $ip, $left, $lookup, $livePeers));

        $flag = null;

        if ($verdict->kind === LocationVerdict::AMBIGUOUS) {
            $policy = (string) config('bloodhound.anti_cheat.ambiguous_locations', 'flag');

            if ($policy === 'refuse') {
                return ['passed' => false, 'reason' => $verdict->reason];
            }

            if ($policy !== 'allow') {
                $flag = self::AMBIGUOUS_LOCATION.': moved across address families from a place the member already uses; refused under ambiguous_locations=refuse ('.$verdict->reason.')';
            }
        } elseif (! $verdict->passed()) {
            return ['passed' => false, 'reason' => $verdict->reason];
        }

        $record = $lookup->isLive() ? $lookup->baseline : null;

        // A new peer: at most max_clients_per_torrent of the member's live
        // peer IDs on this torrent. Many peer IDs on one torrent pollute its
        // swarm and inflate its seeder count; a member's number of torrents
        // is never limited, because the members who seed thousands of them
        // from one seedbox are the ones keeping the site alive.
        if ($record === null) {
            $max = (int) config('bloodhound.anti_cheat.max_clients_per_torrent', 5);
            $clients = 0;

            foreach ($livePeers as $otherPeerId => $peer) {
                if ((string) $otherPeerId !== $peerId && ($peer['user_id'] ?? null) === $userId) {
                    $clients++;
                }
            }

            if ($clients >= $max) {
                return [
                    'passed' => false,
                    'reason' => "Client limit reached ({$max} clients per torrent)",
                ];
            }
        }

        return ['passed' => true, 'reason' => null, 'flag' => $flag];
    }

    /**
     * Forfeit a direction whose claimed speed is over the cap.
     *
     * Measured over the real interval since the baseline the credit uses
     * (live, retained or recovered: F14), against the seedbox cap when the
     * announce comes from one of the member's approved seedboxes and the
     * ordinary cap otherwise.
     *
     * Forfeited rather than refused. A refusal left the baseline where it
     * was, so the next announce credited the same bytes over a longer window
     * and the cap delayed nothing more than a minute (F9). Forfeiting
     * advances the baseline: the claimed bytes never land, and the honest
     * direction and the member's time are still credited.
     *
     * @return array{forfeit_upload: bool, forfeit_download: bool, reason: ?string}
     */
    public function checkSpeed(int $userId, string $ip, int $uploaded, int $downloaded, PeerLookup $lookup): array
    {
        $none = ['forfeit_upload' => false, 'forfeit_download' => false, 'reason' => null];
        $credit = $lookup->credit($uploaded, $downloaded);

        if ($credit['prior_at'] === null) {
            return $none;
        }

        $elapsed = $credit['measured_at'] - $credit['prior_at'];

        if ($elapsed <= 0) {
            return $none;
        }

        $seedbox = $this->seedboxes->isSeedbox($userId, $ip);
        $prefix = $seedbox ? 'bloodhound.anti_cheat.seedbox.' : 'bloodhound.anti_cheat.';
        $default = $seedbox ? 1_250_000_000 : 100 * 1024 * 1024;

        $result = $none;
        $reasons = [];

        foreach (['upload' => $credit['upload_delta'], 'download' => $credit['download_delta']] as $direction => $delta) {
            $cap = (int) config("{$prefix}max_{$direction}_speed", $default);
            $speed = $delta / $elapsed;

            if ($speed > $cap) {
                $result["forfeit_{$direction}"] = true;
                $reasons[] = sprintf(
                    '%s over the %scap (%s claimed, cap %s): %d bytes not credited',
                    ucfirst($direction), $seedbox ? 'seedbox ' : '', self::rate($speed), self::rate($cap), $delta,
                );
            }
        }

        $result['reason'] = $reasons === [] ? null : implode('; ', $reasons);

        return $result;
    }

    /**
     * A transfer rate a person can read: B/s, KB/s, MB/s or GB/s.
     */
    private static function rate(float $bytesPerSecond): string
    {
        foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $unit => $size) {
            if ($bytesPerSecond >= $size) {
                return round($bytesPerSecond / $size, 2)." {$unit}/s";
            }
        }

        return round($bytesPerSecond).' B/s';
    }

    /**
     * Check swarm-level consistency.
     *
     * The total downloaded by all peers should not significantly exceed
     * the total uploaded + the original seeder's contribution.
     */
    public function checkSwarmConsistency(int $torrentId, int $torrentSize): array
    {
        $stats = $this->peerService->getSwarmStats($torrentId);

        $totalUploaded = $stats['total_uploaded'];
        $totalDownloaded = $stats['total_downloaded'];
        $seeders = max(1, $stats['seeders']);

        // Expected: total_downloaded <= total_uploaded + (seeders * torrent_size)
        // The seeders could have provided the data initially
        $maxExpectedDownloaded = $totalUploaded + ($seeders * $torrentSize);

        // Allow 20% variance for overhead, timing issues, etc.
        if ($totalDownloaded > $maxExpectedDownloaded * 1.2) {
            $ratio = round($totalDownloaded / max(1, $totalUploaded), 2);

            return [
                'passed' => false,
                'reason' => "Swarm anomaly: downloaded/uploaded ratio ({$ratio}) too high",
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Record a suspicious peer for later analysis.
     */
    public function flagSuspicious(int $userId, int $torrentId, string $reason): void
    {
        $redis = $this->redis();
        $key = $this->prefix().'suspicious';

        $data = json_encode([
            'user_id' => $userId,
            'torrent_id' => $torrentId,
            'reason' => $reason,
            'timestamp' => time(),
        ]);

        $redis->lpush($key, $data);
        $redis->ltrim($key, 0, 999); // Keep last 1000 entries
    }

    /**
     * Get recent suspicious activity.
     *
     * @return array<array{user_id: int, torrent_id: int, reason: string, timestamp: int}>
     */
    public function getSuspiciousActivity(int $limit = 100): array
    {
        $redis = $this->redis();
        $entries = $redis->lrange($this->prefix().'suspicious', 0, $limit - 1);

        return array_map(fn ($entry) => json_decode($entry, true), $entries);
    }

    /**
     * Dispatch a cheat detection event.
     */
    private function dispatchCheatEvent(string $type, ?int $userId, int $torrentId, ?string $reason): void
    {
        $this->flagSuspicious($userId ?? 0, $torrentId, "{$type}: {$reason}");

        // Dispatch event for listeners
        event(new CheatDetected($type, $userId, $torrentId, $reason));
    }

    private function prefix(): string
    {
        return config('threepio.redis.prefix', 'marque:');
    }

    private function redis(): Connection
    {
        return Redis::connection(config('threepio.redis.connection', 'default'));
    }
}
