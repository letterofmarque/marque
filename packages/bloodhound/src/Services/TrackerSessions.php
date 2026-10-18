<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Carbon;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Support\PeerIdColumn;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Contracts\TrackerSessionsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\TrackerSession;
use RuntimeException;

/**
 * A member's live sessions, and ending one (Job #193, D8).
 *
 * The leeching limit of one location per torrent is only fair if a member
 * can clear a session that outlived its client: a client restarted with a new
 * peer ID somewhere else is refused until the old one expires, otherwise.
 * Ending it removes the peer and any retained baseline, and writes an 'ended'
 * ledger row: it credits nothing, and like a stop it closes the session, so
 * the audit expects no baseline after it and recovery offers none. Whatever
 * the session moved since its last announce is not credited; the member
 * chose that over waiting.
 */
final class TrackerSessions implements TrackerSessionsInterface
{
    public function __construct(
        private readonly PeerService $peers,
    ) {}

    public function sessionsFor(UserInterface $user): array
    {
        $peers = $this->peers->peersOfUser((int) $user->getAuthIdentifier());

        $names = Torrent::query()
            ->whereIn('id', array_unique(array_column($peers, 'torrent_id')))
            ->pluck('name', 'id');

        $sessions = array_map(fn (array $peer) => new TrackerSession(
            id: $peer['torrent_id'].':'.bin2hex($peer['peer_id']),
            torrentId: $peer['torrent_id'],
            torrentName: $names->get($peer['torrent_id']),
            client: (string) ($peer['peer']['user_agent'] ?? ''),
            ip: (string) $peer['peer']['ip'],
            seeding: (bool) $peer['peer']['is_seeder'],
            lastAnnounceAt: Carbon::createFromTimestamp((int) $peer['peer']['last_action'], date_default_timezone_get()),
            uploaded: (int) $peer['peer']['uploaded'],
            downloaded: (int) $peer['peer']['downloaded'],
        ), $peers);

        // Leeching first: a leeching session is the one that can stand in a
        // restarted client's way.
        usort($sessions, fn (TrackerSession $a, TrackerSession $b) => [$a->seeding, $b->lastAnnounceAt->getTimestamp()]
            <=> [$b->seeding, $a->lastAnnounceAt->getTimestamp()]);

        return $sessions;
    }

    public function endSession(UserInterface $user, string $sessionId): bool
    {
        [$torrentId, $hex] = explode(':', $sessionId, 2) + [1 => ''];

        if (! ctype_digit($torrentId) || $hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return false;
        }

        $torrentId = (int) $torrentId;
        $peerId = (string) hex2bin($hex);
        $userId = (int) $user->getAuthIdentifier();

        // Under the peer's lock, as an announce is: an announce from this
        // session landing at the same moment would otherwise write its record
        // back after the removal, or be credited against the removed one.
        $ended = $this->peers->withPeerLock($torrentId, $peerId, function () use ($torrentId, $peerId, $userId) {
            $peer = $this->peers->getPeer($torrentId, $peerId);

            if ($peer === null || ($peer['user_id'] ?? null) !== $userId) {
                return false;
            }

            // The row first, as for an announce: if it cannot be written the
            // session is left as it was.
            (new AnnounceLog)->forceFill([
                'user_id' => $userId,
                'torrent_id' => $torrentId,
                'peer_id' => PeerIdColumn::encode($peerId),
                'event' => AnnounceLog::EVENT_ENDED,
                'ip' => $peer['ip'],
                'port' => $peer['port'],
                'user_agent' => $peer['user_agent'] ?? null,
                // The counters the session last reported, so the row says
                // where it stood when it ended.
                'uploaded' => $peer['uploaded'],
                'downloaded' => $peer['downloaded'],
                'left' => $peer['left'],
                'upload_delta' => 0,
                'download_delta' => 0,
                'seed_seconds' => 0,
                'leech_seconds' => 0,
                'prior_up' => null,
                'prior_down' => null,
                'prior_at' => null,
                'anti_cheat_flagged' => false,
                'anti_cheat_reason' => null,
                'created_at' => Carbon::now(),
            ])->save();

            $this->peers->removePeer($torrentId, $peerId);
            $this->syncSwarmCounts($torrentId);

            return true;
        });

        if ($ended === null) {
            throw new RuntimeException('That session is busy with an announce right now; try again in a moment.');
        }

        return $ended;
    }

    /**
     * Project the torrent's live counts onto its row, as an announce does, so
     * the catalogue does not show the ended peer until the next one.
     */
    private function syncSwarmCounts(int $torrentId): void
    {
        $torrent = Torrent::query()->find($torrentId);
        $seeders = $this->peers->getSeeders($torrentId);
        $leechers = $this->peers->getLeechers($torrentId);

        if ($torrent === null || ($torrent->seeders === $seeders && $torrent->leechers === $leechers)) {
            return;
        }

        $torrent->forceFill(['seeders' => $seeders, 'leechers' => $leechers])->save();
    }
}
