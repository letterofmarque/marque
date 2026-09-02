<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Marque\Bloodhound\Models\AnnounceLog;

/**
 * Writes one row to announce_log for a single announce. Off by default
 * (config('bloodhound.announce_log.enabled')) — only ever dispatched by
 * AnnounceService when the feature is on, never dispatched-but-no-op. See
 * Spec #98.
 *
 * Same shape as UpdateUserStats: a lightweight job carrying pre-computed
 * values, so the hot announce path never blocks on the actual write.
 */
class LogAnnounce implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $torrentId,
        public readonly string $peerId,
        public readonly string $event,
        public readonly string $ip,
        public readonly int $port,
        public readonly ?string $userAgent,
        public readonly int $uploaded,
        public readonly int $downloaded,
        public readonly int $left,
        public readonly int $uploadDelta,
        public readonly int $downloadDelta,
        public readonly bool $antiCheatFlagged,
        public readonly ?string $antiCheatReason,
    ) {}

    public function handle(): void
    {
        AnnounceLog::create([
            'user_id' => $this->userId,
            'torrent_id' => $this->torrentId,
            'peer_id' => $this->peerId,
            'event' => $this->event,
            'ip' => $this->ip,
            'port' => $this->port,
            'user_agent' => $this->userAgent,
            'uploaded' => $this->uploaded,
            'downloaded' => $this->downloaded,
            'left' => $this->left,
            'upload_delta' => $this->uploadDelta,
            'download_delta' => $this->downloadDelta,
            'anti_cheat_flagged' => $this->antiCheatFlagged,
            'anti_cheat_reason' => $this->antiCheatReason,
        ]);
    }
}
