<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An operator's correction to a member's totals, with who made it and why
 * (Job #213).
 *
 * The ledger is its rows, its carry and its adjustments: reconcile and rebuild
 * read all three (LedgerExtras). Append-only. A wrong adjustment is answered by
 * another one signed the other way, so the record of what was decided, by
 * whom, survives the correction. Editing or deleting one through the model
 * throws; nothing in bloodhound writes to this table any other way.
 *
 * torrent_id 0 is the member as a whole: credit beyond their torrents'.
 *
 * @property int $id
 * @property int $user_id
 * @property int $torrent_id
 * @property int $uploaded
 * @property int $downloaded
 * @property int $seedtime
 * @property int $leechtime
 * @property string $reason
 * @property int $made_by
 * @property Carbon|null $created_at
 */
class LedgerAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ledger_adjustments';

    protected $fillable = [
        'user_id',
        'torrent_id',
        'uploaded',
        'downloaded',
        'seedtime',
        'leechtime',
        'reason',
        'made_by',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger adjustments are append-only: answer a wrong one with another, signed the other way.'));
        static::deleting(fn () => throw new LogicException('Ledger adjustments are append-only: answer a wrong one with another, signed the other way.'));
    }

    /**
     * Wherever announce_log lives (Spec #98), beside the carry.
     */
    public function getConnectionName(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'torrent_id' => 'integer',
            'uploaded' => 'integer',
            'downloaded' => 'integer',
            'seedtime' => 'integer',
            'leechtime' => 'integer',
            'made_by' => 'integer',
        ];
    }
}
