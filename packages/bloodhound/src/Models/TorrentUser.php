<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marque\Trove\Models\Torrent;

/**
 * What one user did on one torrent.
 *
 * The intersection the tracker previously computed on every announce and threw
 * away: deltas went onto the user (all torrents combined) and onto the Redis
 * swarm totals (all users combined), and nothing kept the pair.
 *
 * A projection of the ledger, not a source of truth — every value here is
 * rebuildable from announce_log, which is what makes users.uploaded verifiable
 * rather than an accumulator nothing can check. See Spec #99.
 *
 * Replaces `snatches`. That table recorded one date per user+torrent and
 * overwrote it on a redownload, so the original completion date was lost —
 * which is exactly the date a hit-and-run rule needs.
 *
 * @property int $id
 * @property int $user_id
 * @property int $torrent_id
 * @property int $uploaded
 * @property int $downloaded
 * @property int $seedtime
 * @property Carbon|null $first_completed_at
 * @property Carbon|null $last_completed_at
 * @property int $times_completed
 */
class TorrentUser extends Model
{
    protected $table = 'torrent_user';

    protected $fillable = [
        'user_id',
        'torrent_id',
        'uploaded',
        'downloaded',
        'seedtime',
        'first_completed_at',
        'last_completed_at',
        'times_completed',
    ];

    protected function casts(): array
    {
        return [
            'uploaded' => 'integer',
            'downloaded' => 'integer',
            'seedtime' => 'integer',
            'times_completed' => 'integer',
            'first_completed_at' => 'datetime',
            'last_completed_at' => 'datetime',
        ];
    }

    /**
     * Record that this user completed this torrent.
     *
     * `first_completed_at` is set once and never touched again — that is the
     * defect this replaces. A user who completes a torrent, deletes it, and
     * fetches it again months later has genuinely completed it twice, and both
     * facts matter: when they first had it, and that they came back.
     *
     * Note that a completion says nothing about bytes. A torrent whose third
     * file was corrupt fires `completed` again after refetching only that
     * piece, so two completions can mean one-and-a-bit downloads' worth of
     * traffic. The byte columns are projected from ledger deltas separately
     * and neither number can be derived from the other.
     */
    public static function recordCompletion(int $userId, int $torrentId, ?Carbon $at = null): self
    {
        $at ??= Carbon::now();

        $row = static::firstOrNew([
            'user_id' => $userId,
            'torrent_id' => $torrentId,
        ]);

        $row->first_completed_at ??= $at;
        $row->last_completed_at = $at;

        if ($row->countsAsNewCompletion($at)) {
            $row->times_completed = ($row->times_completed ?? 0) + 1;
        }

        $row->save();

        return $row;
    }

    /**
     * Is this a new completion, or the same download announcing again?
     *
     * `event=completed` is a client-supplied parameter that nothing validates,
     * and peer_id is regenerated per client session — so one download reports
     * completion repeatedly whenever a client restarts or the user runs a
     * second machine. Counting each one inflated the total arbitrarily,
     * bounded only by how many happened to slip past an anti-cheat check aimed
     * at something else entirely.
     *
     * Time is the only honest discriminator here. Two announces minutes apart
     * are one download; two six months apart are a genuine redownload — the
     * user deleted it and fetched it again, or refetched a corrupt piece.
     * Nothing in the announce itself distinguishes them, so the cooldown does.
     *
     * The default is a day: comfortably longer than any client-restart churn,
     * comfortably shorter than a real return to a torrent. An operator who
     * disagrees can set `bloodhound.completion_cooldown`.
     */
    protected function countsAsNewCompletion(Carbon $at): bool
    {
        if ($this->times_completed === null || $this->times_completed === 0) {
            return true;
        }

        $previous = $this->getOriginal('last_completed_at');

        if ($previous === null) {
            return true;
        }

        $cooldown = (int) config('bloodhound.completion_cooldown', 86400);

        return Carbon::parse($previous)->addSeconds($cooldown)->lte($at);
    }

    /**
     * The user this row belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('trove.user_model', 'App\\Models\\User'));
    }

    /**
     * The torrent this row belongs to.
     */
    public function torrent(): BelongsTo
    {
        return $this->belongsTo(Torrent::class);
    }
}
