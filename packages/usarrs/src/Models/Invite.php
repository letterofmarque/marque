<?php

declare(strict_types=1);

namespace Marque\Usarrs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Marque\Usarrs\Enums\InviteStatus;

/**
 * @property int $id
 * @property string $code
 * @property int $creator_id
 * @property string|null $recipient_email
 * @property int|null $used_by_id
 * @property InviteStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invite extends Model
{
    protected $fillable = [
        'code',
        'creator_id',
        'recipient_email',
        'used_by_id',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InviteStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('trove.user_model', 'App\\Models\\User'), 'creator_id');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(config('trove.user_model', 'App\\Models\\User'), 'used_by_id');
    }

    public function isValid(): bool
    {
        return $this->status === InviteStatus::Pending
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public static function generateCode(): string
    {
        return bin2hex(random_bytes(16));
    }
}
