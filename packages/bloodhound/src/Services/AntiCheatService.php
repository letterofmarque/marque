<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Events\CheatDetected;
use Marque\Threepio\Services\PeerService;

/**
 * Anti-cheat detection service for the tracker.
 *
 * Implements multiple detection strategies:
 * - Speed sanity checks (impossibly fast upload/download)
 * - Announce frequency limiting
 * - Connection limiting (per user, per IP)
 * - Swarm consistency checking (total uploaded vs downloaded)
 * - Port blacklisting
 */
final class AntiCheatService
{
    private string $prefix;

    public function __construct(
        private readonly PeerService $peerService,
    ) {
        $this->prefix = config('threepio.redis.prefix', 'marque:');
    }

    /**
     * Run all anti-cheat checks on an announce request.
     *
     * @return array{passed: bool, reason: ?string}
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
    ): array {
        if (! config('bloodhound.anti_cheat.enabled', true)) {
            return ['passed' => true, 'reason' => null];
        }

        // Check port blacklist
        $portCheck = $this->checkPort($port);
        if (! $portCheck['passed']) {
            return $portCheck;
        }

        // Check announce frequency
        $frequencyCheck = $this->checkAnnounceFrequency($torrentId, $peerId);
        if (! $frequencyCheck['passed']) {
            return $frequencyCheck;
        }

        // Check connection limits
        $connectionCheck = $this->checkConnectionLimits($torrentId, $userId, $ip);
        if (! $connectionCheck['passed']) {
            return $connectionCheck;
        }

        // Check speed sanity
        $speedCheck = $this->checkSpeedSanity($torrentId, $peerId, $uploaded, $downloaded);
        if (! $speedCheck['passed']) {
            $this->dispatchCheatEvent('speed_violation', $userId, $torrentId, $speedCheck['reason']);

            return $speedCheck;
        }

        // Check data sanity (can't download more than torrent size, etc.)
        $dataCheck = $this->checkDataSanity($uploaded, $downloaded, $left, $torrentSize);
        if (! $dataCheck['passed']) {
            $this->dispatchCheatEvent('data_violation', $userId, $torrentId, $dataCheck['reason']);

            return $dataCheck;
        }

        // Check swarm consistency (run periodically, not on every announce)
        // This is expensive so we sample
        if (mt_rand(1, 100) <= 5) { // 5% of announces
            $swarmCheck = $this->checkSwarmConsistency($torrentId, $torrentSize);
            if (! $swarmCheck['passed']) {
                // Don't fail the announce, but log for investigation
                $this->dispatchCheatEvent('swarm_anomaly', null, $torrentId, $swarmCheck['reason']);
            }
        }

        return ['passed' => true, 'reason' => null];
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
     * Check announce frequency (prevent hammering).
     */
    public function checkAnnounceFrequency(int $torrentId, string $peerId): array
    {
        $redis = $this->redis();
        $key = $this->prefix."announce_time:{$torrentId}:{$peerId}";
        $minGap = (int) config('bloodhound.anti_cheat.min_announce_gap', 60);

        $lastAnnounce = $redis->get($key);

        if ($lastAnnounce !== null) {
            $elapsed = time() - (int) $lastAnnounce;

            if ($elapsed < $minGap) {
                return [
                    'passed' => false,
                    'reason' => "Announcing too frequently (wait {$minGap}s between announces)",
                ];
            }
        }

        // Update last announce time
        $redis->setex($key, $minGap * 2, time());

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Check connection limits (per user per torrent, per IP).
     */
    public function checkConnectionLimits(int $torrentId, int $userId, string $ip): array
    {
        $maxPerTorrent = (int) config('bloodhound.anti_cheat.max_connections_per_torrent', 3);
        $maxPerIp = (int) config('bloodhound.anti_cheat.max_connections_per_ip', 10);

        // Check user connections on this torrent
        $userPeerCount = $this->peerService->getUserPeerCountForTorrent($userId, $torrentId);
        if ($userPeerCount >= $maxPerTorrent) {
            return [
                'passed' => false,
                'reason' => "Connection limit exceeded ({$maxPerTorrent} per torrent)",
            ];
        }

        // Check IP connections
        $ipPeerCount = $this->peerService->getIpPeerCount($ip);
        if ($ipPeerCount >= $maxPerIp) {
            return [
                'passed' => false,
                'reason' => "Connection limit exceeded ({$maxPerIp} per IP)",
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Check for impossibly fast upload/download speeds.
     */
    public function checkSpeedSanity(int $torrentId, string $peerId, int $uploaded, int $downloaded): array
    {
        $maxUploadSpeed = (int) config('bloodhound.anti_cheat.max_upload_speed', 100 * 1024 * 1024);
        $maxDownloadSpeed = (int) config('bloodhound.anti_cheat.max_download_speed', 100 * 1024 * 1024);

        $existingPeer = $this->peerService->getPeer($torrentId, $peerId);

        if ($existingPeer === null) {
            return ['passed' => true, 'reason' => null];
        }

        $timeDelta = time() - $existingPeer['last_action'];

        if ($timeDelta <= 0) {
            return ['passed' => true, 'reason' => null];
        }

        // Calculate deltas
        $uploadDelta = max(0, $uploaded - $existingPeer['uploaded']);
        $downloadDelta = max(0, $downloaded - $existingPeer['downloaded']);

        // Calculate speeds
        $uploadSpeed = $uploadDelta / $timeDelta;
        $downloadSpeed = $downloadDelta / $timeDelta;

        if ($uploadSpeed > $maxUploadSpeed) {
            $speedMbps = round($uploadSpeed / 1024 / 1024, 2);
            $maxMbps = round($maxUploadSpeed / 1024 / 1024, 2);

            return [
                'passed' => false,
                'reason' => "Impossible upload speed detected ({$speedMbps} MB/s, max: {$maxMbps} MB/s)",
            ];
        }

        if ($downloadSpeed > $maxDownloadSpeed) {
            $speedMbps = round($downloadSpeed / 1024 / 1024, 2);
            $maxMbps = round($maxDownloadSpeed / 1024 / 1024, 2);

            return [
                'passed' => false,
                'reason' => "Impossible download speed detected ({$speedMbps} MB/s, max: {$maxMbps} MB/s)",
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * Check data sanity.
     */
    public function checkDataSanity(int $uploaded, int $downloaded, int $left, int $torrentSize): array
    {
        // Can't have downloaded more than the torrent size
        if ($downloaded > $torrentSize * 1.1) { // 10% tolerance for protocol overhead
            return [
                'passed' => false,
                'reason' => 'Downloaded more than torrent size',
            ];
        }

        // Left + downloaded should roughly equal torrent size for leechers
        if ($left > 0) {
            $total = $left + $downloaded;
            // Allow some variance for partial pieces, etc.
            if ($total < $torrentSize * 0.9 || $total > $torrentSize * 1.1) {
                return [
                    'passed' => false,
                    'reason' => 'Data inconsistency (left + downloaded != torrent size)',
                ];
            }
        }

        return ['passed' => true, 'reason' => null];
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
        $key = $this->prefix.'suspicious';

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
        $entries = $redis->lrange($this->prefix.'suspicious', 0, $limit - 1);

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

    private function redis(): Connection
    {
        return Redis::connection(config('threepio.redis.connection', 'default'));
    }
}
