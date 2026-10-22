<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a member stands against the ratio rule, when that is anything but ok
 * (#10732). See the migration for what the columns hold and who writes them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $state ok, watch or restricted
 * @property Carbon|null $watch_started_at
 * @property Carbon|null $deadline_at
 * @property Carbon|null $restricted_at
 * @property Carbon|null $exempted_at
 * @property int|null $exempted_by
 * @property string|null $exempt_reason
 */
class MemberStanding extends Model
{
    protected $fillable = [
        'user_id',
        'state',
        'watch_started_at',
        'deadline_at',
        'restricted_at',
        'exempted_at',
        'exempted_by',
        'exempt_reason',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'watch_started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'restricted_at' => 'datetime',
            'exempted_at' => 'datetime',
            'exempted_by' => 'integer',
        ];
    }

    public function isExempted(): bool
    {
        return $this->exempted_at !== null;
    }
}
