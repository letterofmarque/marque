<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Support\Seedbox;

/**
 * Stands in for a tracker's seedbox store. usarrs must not depend on
 * bloodhound, so the screens are proven against the contract alone.
 */
class FakeSeedboxes implements SeedboxesInterface
{
    /** @var array<int, Seedbox> */
    public array $seedboxes = [];

    public bool $approval = true;

    private int $next = 1;

    public function requiresApproval(): bool
    {
        return $this->approval;
    }

    public function forUser(UserInterface $user): array
    {
        return array_values(array_filter(
            array_reverse($this->seedboxes),
            fn (Seedbox $seedbox) => $seedbox->userId === $user->getAuthIdentifier(),
        ));
    }

    public function register(UserInterface $user, string $network, ?string $label = null): Seedbox
    {
        if (! str_contains($network, '.') && ! str_contains($network, ':')) {
            throw new InvalidArgumentException("\"{$network}\" is not an IP address or range.");
        }

        $id = $this->next++;

        return $this->seedboxes[$id] = new Seedbox(
            $id, $user->getAuthIdentifier(), $network, $label,
            $this->approval ? Seedbox::PENDING : Seedbox::APPROVED, CarbonImmutable::now(),
        );
    }

    public function remove(UserInterface $user, int $seedboxId): void
    {
        if (($this->seedboxes[$seedboxId] ?? null)?->userId !== $user->getAuthIdentifier()) {
            throw new ModelNotFoundException;
        }

        unset($this->seedboxes[$seedboxId]);
    }

    public function all(?string $status = null): array
    {
        return array_values(array_filter(
            array_reverse($this->seedboxes),
            fn (Seedbox $seedbox) => $status === null || $seedbox->status === $status,
        ));
    }

    public function approve(int $seedboxId, UserInterface $admin): Seedbox
    {
        return $this->decide($seedboxId, Seedbox::APPROVED, $admin);
    }

    public function revoke(int $seedboxId, UserInterface $admin): Seedbox
    {
        return $this->decide($seedboxId, Seedbox::REVOKED, $admin);
    }

    private function decide(int $seedboxId, string $status, UserInterface $admin): Seedbox
    {
        $old = $this->seedboxes[$seedboxId] ?? throw new ModelNotFoundException;

        return $this->seedboxes[$seedboxId] = new Seedbox(
            $old->id, $old->userId, $old->network, $old->label, $status,
            $old->registeredAt, CarbonImmutable::now(), $admin->getAuthIdentifier(),
        );
    }
}
