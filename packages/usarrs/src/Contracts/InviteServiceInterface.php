<?php

declare(strict_types=1);

namespace Marque\Usarrs\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Marque\Usarrs\Models\Invite;

interface InviteServiceInterface
{
    public function create(Authenticatable $creator, ?string $recipientEmail = null): Invite;

    public function redeem(Invite $invite, Authenticatable $user): void;

    /**
     * With an actor, InvitePolicy::revoke is checked against them, and revoking
     * someone else's invite is recorded as a staff action.
     */
    public function revoke(Invite $invite, ?Authenticatable $actor = null, ?string $reason = null): void;

    public function findByCode(string $code): ?Invite;

    public function listForUser(Authenticatable $user, int $perPage = 25): LengthAwarePaginator;
}
