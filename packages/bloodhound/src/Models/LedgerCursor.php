<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How far a projection has consumed the ledger.
 *
 * Read and advanced inside the same transaction as the projection it tracks,
 * which is what makes aggregation crash-safe without any help from the queue:
 * a worker that dies mid-batch rolls back both the projection write and the
 * cursor move, so the next run redoes the batch rather than skipping it.
 *
 * See Spec #99.
 *
 * @property int $id
 * @property string $stream
 * @property int $position
 */
class LedgerCursor extends Model
{
    protected $fillable = [
        'stream',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Where this stream has read up to. Zero means it has read nothing.
     */
    public static function positionFor(string $stream): int
    {
        return (int) static::query()->where('stream', $stream)->value('position');
    }
}
