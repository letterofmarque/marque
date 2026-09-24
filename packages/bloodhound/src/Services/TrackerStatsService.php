<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\TorrentStats;
use Marque\Trove\Support\TrackerStats;

/**
 * bloodhound's answer to trove's TrackerStatsInterface (Spec #119).
 *
 * The one place outside the announce path that touches bloodhound's per-user
 * columns, so that nothing else has to. Every read goes to storage by the
 * user's key rather than trusting attributes on the instance passed in: the
 * caller's model is usually whatever auth() loaded at the start of the
 * request, and the ledger may have moved the figures on since. It also means
 * nothing here depends on HasTrackerStats being applied to the consumer's User
 * model — the columns are bloodhound's, the trait is only a convenience.
 *
 * Deliberately does not consult ratio_mode. Nothing does (job #10732), the
 * figures accumulate identically in every mode, and reporting a distinction
 * the data does not have would invent behaviour rather than expose it.
 */
class TrackerStatsService implements TrackerStatsInterface
{
    public function statsFor(UserInterface $user): ?TrackerStats
    {
        $row = $this->userQuery($user)->first(['uploaded', 'downloaded', 'seedtime']);

        if ($row === null) {
            return null;
        }

        return new TrackerStats(
            uploaded: (int) $row->getAttribute('uploaded'),
            downloaded: (int) $row->getAttribute('downloaded'),
            seedtime: (int) $row->getAttribute('seedtime'),
        );
    }

    public function statsForTorrent(UserInterface $user, Torrent $torrent): ?TorrentStats
    {
        $row = TorrentUser::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('torrent_id', $torrent->getKey())
            ->first();

        if ($row === null) {
            return null;
        }

        return new TorrentStats(
            uploaded: $row->uploaded,
            downloaded: $row->downloaded,
            seedtime: $row->seedtime,
            firstCompletedAt: $row->first_completed_at?->toImmutable(),
            lastCompletedAt: $row->last_completed_at?->toImmutable(),
            timesCompleted: $row->times_completed,
        );
    }

    public function announceKeyFor(UserInterface $user): ?string
    {
        $key = AnnounceKey::query()->where('user_id', $user->getAuthIdentifier())->value('key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Also how a user with no key is issued one.
     *
     * Writes announce_keys only. users.announce_key is deprecated and nothing
     * writes it — including here — so a consumer still reading that column
     * sees a stale key rather than a current one. That is the intended break
     * (Spec #119); read announceKeyFor() instead.
     */
    public function regenerateAnnounceKey(UserInterface $user): string
    {
        $key = $this->mintAnnounceKey();

        $record = AnnounceKey::query()->where('user_id', $user->getAuthIdentifier())->first()
            ?? (new AnnounceKey)->forceFill(['user_id' => $user->getAuthIdentifier()]);

        $record->forceFill(['key' => $key])->save();

        return $key;
    }

    /**
     * 32 alphanumeric characters — what the default key_pattern accepts, and
     * what config/bloodhound.php documents bloodhound as minting.
     */
    protected function mintAnnounceKey(): string
    {
        return Str::random(32);
    }

    /**
     * @return Builder<Model>
     */
    private function userQuery(UserInterface $user): Builder
    {
        /** @var class-string<Model> $model */
        $model = config('trove.user_model');

        return $model::query()->whereKey($user->getAuthIdentifier());
    }
}
