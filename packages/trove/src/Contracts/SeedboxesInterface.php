<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use InvalidArgumentException;
use Marque\Trove\Support\Seedbox;

/**
 * Members' registered seedboxes, for packages that do not own them.
 *
 * Declared here and implemented by the tracker (bloodhound), the same split as
 * TrackerStatsInterface: a seedbox exists so its announces are measured
 * against a higher speed cap, which is the tracker's business; showing and
 * managing them is the frontend's.
 *
 * **Ask the container whether this exists before asking it anything.** Only a
 * tracker that measures announce speeds binds it:
 *
 *     if (app()->bound(SeedboxesInterface::class)) { ... }
 */
interface SeedboxesInterface
{
    /**
     * Whether a newly registered seedbox waits for an admin to approve it.
     * When false, registering approves it.
     */
    public function requiresApproval(): bool;

    /**
     * The member's seedboxes, newest first, whatever their status.
     *
     * @return list<Seedbox>
     */
    public function forUser(UserInterface $user): array;

    /**
     * Register a network (an address, or a CIDR range) as one of the member's
     * seedboxes.
     *
     * @throws InvalidArgumentException with a message fit to show the member,
     *                                  when the network is malformed, wider than the tracker allows, or
     *                                  already registered by them
     */
    public function register(UserInterface $user, string $network, ?string $label = null): Seedbox;

    /**
     * Remove one of the member's own seedboxes. Another member's is not found.
     */
    public function remove(UserInterface $user, int $seedboxId): void;

    /**
     * Every member's seedboxes, newest first, optionally of one status, for
     * an admin.
     *
     * @return list<Seedbox>
     */
    public function all(?string $status = null): array;

    /**
     * Approve a seedbox, recording who did it and when.
     */
    public function approve(int $seedboxId, UserInterface $admin): Seedbox;

    /**
     * Revoke a seedbox's approval, recording who did it and when. Its
     * announces go back to the ordinary cap.
     */
    public function revoke(int $seedboxId, UserInterface $admin): Seedbox;
}
