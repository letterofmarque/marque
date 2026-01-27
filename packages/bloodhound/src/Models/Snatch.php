<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marque\Trove\Models\Torrent;

/**
 * Represents a completed download (snatch) event.
 *
 * @property int $id
 * @property int $user_id
 * @property int $torrent_id
 * @property string $ip
 * @property string|null $user_agent
 * @property \Carbon\Carbon $completed_at
 */
class Snatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'torrent_id',
        'ip',
        'user_agent',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the user who completed the download.
     */
    public function user(): BelongsTo
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel);
    }

    /**
     * Get the torrent that was downloaded.
     */
    public function torrent(): BelongsTo
    {
        return $this->belongsTo(Torrent::class);
    }
}
