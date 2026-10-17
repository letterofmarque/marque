<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Marque\Trove\Support\Seedbox as SeedboxData;

/**
 * A network a member has registered as a seedbox.
 *
 * @property int $id
 * @property int $user_id
 * @property string $network
 * @property string|null $label
 * @property string $status
 * @property Carbon|null $decided_at
 * @property int|null $decided_by
 * @property Carbon $created_at
 */
class Seedbox extends Model
{
    protected $fillable = [
        'user_id',
        'network',
        'label',
        'status',
        'decided_at',
        'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'decided_at' => 'datetime',
            'decided_by' => 'integer',
        ];
    }

    public function toData(): SeedboxData
    {
        return new SeedboxData(
            id: $this->id,
            userId: $this->user_id,
            network: $this->network,
            label: $this->label,
            status: $this->status,
            registeredAt: $this->created_at,
            decidedAt: $this->decided_at,
            decidedBy: $this->decided_by,
        );
    }
}
