<?php

declare(strict_types=1);

namespace Marque\Usarrs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marque\Usarrs\Enums\InviteStatus;

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
