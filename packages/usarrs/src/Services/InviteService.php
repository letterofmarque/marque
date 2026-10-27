<?php

declare(strict_types=1);

namespace Marque\Usarrs\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Enums\InviteStatus;
use Marque\Usarrs\Exceptions\InviteAlreadyRedeemed;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Notifications\InviteNotification;

class InviteService implements InviteServiceInterface
{
    public function __construct(
        private readonly StaffActionsInterface $staffActions,
    ) {}

    public function create(Authenticatable $creator, ?string $recipientEmail = null): Invite
    {
        $invite = Invite::create([
            'code' => Invite::generateCode(),
            'creator_id' => $creator->getAuthIdentifier(),
            'recipient_email' => $recipientEmail,
            'status' => InviteStatus::Pending->value,
            'expires_at' => now()->addDays(config('usarrs.invites.expiry_days', 7)),
        ]);

        // To the person invited. This used to notify the creator, so the
        // recipient never got the link (found in Job #141).
        if ($recipientEmail) {
            Notification::route('mail', $recipientEmail)->notify(new InviteNotification($invite));
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

    public function revoke(Invite $invite, ?Authenticatable $actor = null, ?string $reason = null): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('revoke', $invite);
        }

        DB::transaction(function () use ($invite, $actor, $reason): void {
            $from = $invite->status;
            $invite->update(['status' => InviteStatus::Revoked->value]);

            // Revoking your own invite is housekeeping, not a staff action.
            if ($actor !== null && $actor->getAuthIdentifier() !== $invite->creator_id) {
                $this->staffActions->record(StaffAction::of($actor, 'usarrs.invite.revoked', $invite, $reason, [
                    'status' => ['from' => $from->value, 'to' => InviteStatus::Revoked->value],
                ]));
            }
        });
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
