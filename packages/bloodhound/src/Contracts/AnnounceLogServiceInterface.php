<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Contracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Marque\Bloodhound\Models\AnnounceLog;

/**
 * The read side of the announce log (Spec #98) — the query-side counterpart
 * to the LogAnnounce writer.
 *
 * Every method is an investigative question an operator actually asks: what
 * has this user been doing, what happened on this torrent, did these two
 * accounts share an IP, show me everything anti-cheat flagged. Results are
 * newest-first, since investigation starts from what just happened.
 *
 * Nothing here is paginated by design. The log is off by default and only
 * ever queried deliberately (an investigation, a ratio dispute), not rendered
 * on a hot path — and the $since filter is the intended way to bound a result
 * set on a tracker with real volume. If a browsable admin UI lands later
 * (explicitly out of scope for Spec #98), pagination is a question to settle
 * then, against a real UI's needs.
 */
interface AnnounceLogServiceInterface
{
    /**
     * This user's announce history across every torrent.
     *
     * @return Collection<int, AnnounceLog>
     */
    public function forUser(int $userId, ?Carbon $since = null): Collection;

    /**
     * Every user's activity on one torrent.
     *
     * @return Collection<int, AnnounceLog>
     */
    public function forTorrent(int $torrentId, ?Carbon $since = null): Collection;

    /**
     * One user's session history on one torrent — the ratio-dispute query.
     *
     * @return Collection<int, AnnounceLog>
     */
    public function forUserAndTorrent(int $userId, int $torrentId): Collection;

    /**
     * Announces the anti-cheat checks rejected.
     *
     * @return Collection<int, AnnounceLog>
     */
    public function flagged(?Carbon $since = null): Collection;

    /**
     * Announces from one address — multi-account / IP correlation.
     *
     * @return Collection<int, AnnounceLog>
     */
    public function byIp(string $ip, ?Carbon $since = null): Collection;
}
