<?php

declare(strict_types=1);

namespace Marque\Usarrs\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Enums\InviteStatus;
use Marque\Usarrs\Exceptions\InviteAlreadyRedeemed;
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

    /**
     * Claim the invite for $user — only if it is still pending and unexpired
     * *in the database*, checked and written in one statement. It used to
     * write "used" over whatever was there, so registrations racing on one
     * invite all got it (Build #124 CP #763). Run it in the same transaction
     * that creates the account, so losing the race takes the account with it.
     *
     * @throws InviteAlreadyRedeemed
     */
    public function redeem(Invite $invite, Authenticatable $user): void
    {
        $claimed = Invite::query()
            ->whereKey($invite->getKey())
            ->where('status', InviteStatus::Pending->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->update([
                'used_by_id' => $user->getAuthIdentifier(),
                'status' => InviteStatus::Used->value,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new InviteAlreadyRedeemed;
        }

        $invite->refresh();
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
