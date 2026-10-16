<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credit carried forward from ledger rows that have been pruned (#11021).
 *
 * One row per (user, torrent): what the deleted rows contributed to that
 * user's totals on that torrent. Reconcile and rebuild read the ledger as its
 * rows plus this, so pruning neither reads as drift nor lets a rebuild erase
 * credit people earned.
 *
 * @property int $id
 * @property int $user_id
 * @property int $torrent_id
 * @property int $uploaded
 * @property int $downloaded
 * @property int $seedtime
 * @property int $leechtime
 */
class LedgerCarry extends Model
{
    protected $table = 'ledger_carry';

    protected $fillable = [
        'user_id',
        'torrent_id',
        'uploaded',
        'downloaded',
        'seedtime',
        'leechtime',
    ];

    /**
     * Wherever announce_log lives (Spec #98): the carry is part of the ledger,
     * and is written in the same transaction as the rows it replaces.
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
            'seedtime' => 'integer',
            'leechtime' => 'integer',
        ];
    }
}
