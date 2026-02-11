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

    public function revoke(Invite $invite): void;

    public function findByCode(string $code): ?Invite;

    public function listForUser(Authenticatable $user, int $perPage = 25): LengthAwarePaginator;
}
