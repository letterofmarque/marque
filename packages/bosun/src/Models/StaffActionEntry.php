<?php

declare(strict_types=1);

namespace Marque\Bosun\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored staff action.
 *
 * @property int $id
 * @property int $actor_id
 * @property string $action
 * @property string $package
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $reason
 * @property array<string, array{from: scalar|null, to: scalar|null}>|null $changes
 * @property Carbon $created_at
 */
class StaffActionEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'staff_actions';

    protected $fillable = [
        'actor_id',
        'action',
        'package',
        'subject_type',
        'subject_id',
        'reason',
        'changes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(config('trove.user_model', 'App\\Models\\User'), 'actor_id');
    }
}
