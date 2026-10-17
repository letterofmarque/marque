<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Contracts\SeedboxRegistry;
use Marque\Bloodhound\Events\CheatDetected;
use Marque\Bloodhound\Support\Location;
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
 *   torrent, or a new session past the IP's limit (a peer already in the
 *   swarm is never refused by a count).
 *
 * A claimed speed over the cap is not refused: those bytes are forfeited,
 * the baseline moves on, and the rest of the announce is credited. Data and
 * swarm anomalies are reported and never refused.
 */
final class AntiCheatService
{
    public function __construct(
        private readonly PeerService $peerService,
        private readonly SeedboxRegistry $seedboxes,
    ) {}

    /**
     * Run all anti-cheat checks on an announce request.
     *
     * `forfeit_upload` / `forfeit_download` mark a direction whose bytes are
     * not credited; `reason` says why, for the ledger row.
     *
     * @param  array<string, array<string, mixed>>  $livePeers  the torrent's live peers, as PeerService::livePeers() returns them
     * @return array{passed: bool, reason: ?string, forfeit_upload: bool, forfeit_download: bool}
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
        $pass = ['passed' => true, 'reason' => null, 'forfeit_upload' => false, 'forfeit_download' => false];

        if (! config('bloodhound.anti_cheat.enabled', true)) {
            return $pass;
        }

        $portCheck = $this->checkPort($port);
        if (! $portCheck['passed']) {
            return $portCheck + $pass;
        }

        $gapCheck = $this->checkAnnounceGap($event, $lookup);
        if (! $gapCheck['passed']) {
            return $gapCheck + $pass;
        }

        $limitCheck = $this->checkLocationLimits($userId, $ip, $left, $event, $lookup, $livePeers);
        if (! $limitCheck['passed']) {
            return $limitCheck + $pass;
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
    public function checkAnnounceGap(?string $event, PeerLookup $lookup): array
    {
        if ($event !== null || ! $lookup->isLive()) {
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
     * new session past the IP's.
     *
     * Only a peer that is not already in the swarm is counted against a
     * limit: the count used to include the announcing peer, so a member at
     * the limit was refused on every announce from every client (F6). A
     * location is an IP (an IPv6 /64), so a second client at a location the
     * member already uses is not a new one. Seeding and leeching locations
     * are limited separately: the leeching limit is what stops one account
     * being shared to download.
     *
     * @param  array<string, array<string, mixed>>  $livePeers
     */
    public function checkLocationLimits(int $userId, string $ip, int $left, ?string $event, PeerLookup $lookup, array $livePeers): array
    {
        if ($event === 'stopped' || $lookup->isLive()) {
            return ['passed' => true, 'reason' => null];
        }

        $location = Location::of($ip);
        $seeding = $left === 0;
        $locations = [];

        foreach ($livePeers as $peer) {
            if (($peer['user_id'] ?? null) !== $userId) {
                continue;
            }

            $peerLocation = Location::of((string) $peer['ip']);

            if ($peerLocation === $location) {
                $locations = null;

                break;
            }

            if ((((int) $peer['left']) === 0) === $seeding) {
                $locations[$peerLocation] = true;
            }
        }

        if ($locations !== null) {
            $kind = $seeding ? 'seeding' : 'leeching';
            $max = (int) config("bloodhound.anti_cheat.max_{$kind}_locations_per_torrent", $seeding ? 3 : 1);

            if (count($locations) >= $max) {
                return [
                    'passed' => false,
                    'reason' => "Location limit reached ({$max} {$kind} locations per torrent)",
                ];
            }
        }

        $maxPerIp = (int) config('bloodhound.anti_cheat.max_connections_per_ip', 10);

        if ($this->peerService->getIpSessionCount($ip) >= $maxPerIp) {
            return [
                'passed' => false,
                'reason' => "Connection limit exceeded ({$maxPerIp} per IP)",
            ];
        }

        return ['passed' => true, 'reason' => null];
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

        return round($bytesPerSecond)." B/s";
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
