<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A seeding obligation in trouble under the hit-and-run rule (Job #256):
 * pre-warned, and perhaps struck. See the migration for the lifecycle.
 *
 * @property int $id
 * @property int $user_id
 * @property int $torrent_id
 * @property Carbon|null $prewarned_at
 * @property Carbon|null $struck_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $cleared_at
 */
class HitAndRunStrike extends Model
{
    protected $table = 'hit_and_run_strikes';

    protected $fillable = [
        'user_id',
        'torrent_id',
        'prewarned_at',
        'struck_at',
        'expires_at',
        'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'torrent_id' => 'integer',
            'prewarned_at' => 'datetime',
            'struck_at' => 'datetime',
            'expires_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * Struck, not cleared, not lapsed: the strikes that count.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('struck_at')->whereNull('cleared_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->struck_at !== null && $this->cleared_at === null && $this->expires_at?->isFuture() === true;
    }
}
