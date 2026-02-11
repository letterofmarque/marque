<?php

declare(strict_types=1);

namespace Marque\Usarrs\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Enums\InviteStatus;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Notifications\InviteNotification;

class InviteService implements InviteServiceInterface
{
    public function create(Authenticatable $creator, ?string $recipientEmail = null): Invite
    {
        $invite = Invite::create([
            'code' => Invite::generateCode(),
            'creator_id' => $creator->getAuthIdentifier(),
            'recipient_email' => $recipientEmail,
            'status' => InviteStatus::Pending->value,
            'expires_at' => now()->addDays(config('usarrs.invites.expiry_days', 7)),
        ]);

        if ($recipientEmail && method_exists($creator, 'notify')) {
            $creator->notify(new InviteNotification($invite));
        }

        return $invite;
    }

    public function redeem(Invite $invite, Authenticatable $user): void
    {
        $invite->update([
            'used_by_id' => $user->getAuthIdentifier(),
            'status' => InviteStatus::Used->value,
        ]);
    }

    public function revoke(Invite $invite): void
    {
        $invite->update(['status' => InviteStatus::Revoked->value]);
    }

    public function findByCode(string $code): ?Invite
    {
        return Invite::where('code', $code)->first();
    }

    public function listForUser(Authenticatable $user, int $perPage = 25): LengthAwarePaginator
    {
        return Invite::where('creator_id', $user->getAuthIdentifier())
            ->latest()
            ->paginate($perPage);
    }

    public function countActiveForUser(Authenticatable $user): int
    {
        return Invite::where('creator_id', $user->getAuthIdentifier())
            ->where('status', InviteStatus::Pending->value)
            ->count();
    }

    public function canCreateInvite(Authenticatable $user): bool
    {
        $max = config('usarrs.invites.max_per_user', 3);

        return $this->countActiveForUser($user) < $max;
    }
}
