<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marque\Trove\Models\Torrent;

/**
 * A single announce, in full — every started, regular-interval, completed,
 * and stopped request, refused ones included — with what it credited (bytes
 * and seconds), the baseline that was measured from, the cumulative totals
 * the client reported, its fingerprint, and the verdict. The ledger: user and
 * per-torrent totals are sums of these rows. See Spec #99 and Job #193.
 *
 * Append-only — no updated_at, a row is never modified after it's written.
 *
 * @property int $id
 * @property int $user_id
 * @property int $torrent_id
 * @property string $peer_id
 * @property string $event
 * @property string $ip
 * @property int $port
 * @property string|null $user_agent
 * @property int $uploaded
 * @property int $downloaded
 * @property int $left
 * @property int $upload_delta
 * @property int $download_delta
 * @property int $seed_seconds
 * @property int $leech_seconds
 * @property int|null $prior_up
 * @property int|null $prior_down
 * @property Carbon|null $prior_at
 * @property bool $anti_cheat_flagged
 * @property string|null $anti_cheat_reason
 * @property Carbon $created_at
 */
class AnnounceLog extends Model
{
    /**
     * Marks a synthetic row carrying a user's pre-ledger totals forward.
     *
     * Deliberately not a case on threepio's `AnnounceEvent`: that enum is the
     * BitTorrent protocol's event set, and no client ever sends this. It exists
     * so reconciliation has a known starting point on an install that predates
     * the ledger, rather than reporting every user's entire history as drift.
     * See Spec #99's opening-balance decision.
     */
    public const EVENT_OPENING_BALANCE = 'opening_balance';

    protected $table = 'announce_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'torrent_id',
        'peer_id',
        'event',
        'ip',
        'port',
        'user_agent',
        'uploaded',
        'downloaded',
        'left',
        'upload_delta',
        'download_delta',
        'seed_seconds',
        'leech_seconds',
        'prior_up',
        'prior_down',
        'prior_at',
        'anti_cheat_flagged',
        'anti_cheat_reason',
    ];

    /**
     * Swappable connection (Spec #98's "Storage" decision) — null means the
     * app's default connection, same DB as everything else. Set
     * config('bloodhound.announce_log.connection') to isolate this
     * write-heavy table on a separate database with no other code change.
     */
    public function getConnectionName(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    protected function casts(): array
    {
        return [
            'uploaded' => 'integer',
            'downloaded' => 'integer',
            'left' => 'integer',
            'upload_delta' => 'integer',
            'download_delta' => 'integer',
            'seed_seconds' => 'integer',
            'leech_seconds' => 'integer',
            // Nullable — a first announce has no baseline to diff against.
            'prior_up' => 'integer',
            'prior_down' => 'integer',
            'prior_at' => 'datetime',
            'anti_cheat_flagged' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel);
    }

    public function torrent(): BelongsTo
    {
        return $this->belongsTo(Torrent::class);
    }
}
