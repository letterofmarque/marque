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
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property Carbon $created_at
 */
class Seedbox extends Model
{
    protected $fillable = [
        'user_id',
        'network',
        'label',
        'status',
        'approved_at',
        'approved_by',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
            'revoked_at' => 'datetime',
            'revoked_by' => 'integer',
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
            approvedAt: $this->approved_at,
            approvedBy: $this->approved_by,
            revokedAt: $this->revoked_at,
            revokedBy: $this->revoked_by,
        );
    }
}
