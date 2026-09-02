<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Marque\Bloodhound\Contracts\AnnounceLogServiceInterface;
use Marque\Bloodhound\Models\AnnounceLog;

/**
 * Queries the announce log (Spec #98).
 *
 * Every method's where/order pair is deliberately shaped to match one of the
 * composite indexes CP #532 put on the table — leading column filtered by
 * equality, created_at doing both the range filter and the sort, so the index
 * satisfies the whole query and no sort or full scan is needed:
 *
 *   forUser             -> (user_id, created_at)
 *   forTorrent          -> (torrent_id, created_at)
 *   byIp                -> (ip, created_at)
 *   flagged             -> (anti_cheat_flagged)
 *   forUserAndTorrent   -> either of the first two, planner's choice
 *
 * That's why ordering is always created_at and never id, even though on an
 * append-only table the two are effectively equivalent: ordering by id would
 * quietly drop the index's second column and force a sort.
 */
class AnnounceLogService implements AnnounceLogServiceInterface
{
    /**
     * @return Collection<int, AnnounceLog>
     */
    public function forUser(int $userId, ?Carbon $since = null): Collection
    {
        return $this->newest(
            AnnounceLog::query()->where('user_id', $userId),
            $since,
        );
    }

    /**
     * @return Collection<int, AnnounceLog>
     */
    public function forTorrent(int $torrentId, ?Carbon $since = null): Collection
    {
        return $this->newest(
            AnnounceLog::query()->where('torrent_id', $torrentId),
            $since,
        );
    }

    /**
     * Both columns are separately indexed and there's no composite
     * (user_id, torrent_id) index — which is fine: the planner picks whichever
     * of the two it estimates is more selective and filters the rest, so this
     * is an index search either way, never a scan. Adding a third index for
     * this one query isn't worth the write cost on a table this
     * write-dominated.
     *
     * @return Collection<int, AnnounceLog>
     */
    public function forUserAndTorrent(int $userId, int $torrentId): Collection
    {
        return $this->newest(
            AnnounceLog::query()
                ->where('user_id', $userId)
                ->where('torrent_id', $torrentId),
        );
    }

    /**
     * @return Collection<int, AnnounceLog>
     */
    public function flagged(?Carbon $since = null): Collection
    {
        return $this->newest(
            AnnounceLog::query()->where('anti_cheat_flagged', true),
            $since,
        );
    }

    /**
     * @return Collection<int, AnnounceLog>
     */
    public function byIp(string $ip, ?Carbon $since = null): Collection
    {
        return $this->newest(
            AnnounceLog::query()->where('ip', $ip),
            $since,
        );
    }

    /**
     * Applies the optional $since bound and the newest-first ordering that
     * every query here shares.
     *
     * @param  Builder<AnnounceLog>  $query
     * @return Collection<int, AnnounceLog>
     */
    protected function newest(Builder $query, ?Carbon $since = null): Collection
    {
        return $query
            ->when($since, fn (Builder $q, Carbon $since) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->get();
    }
}
