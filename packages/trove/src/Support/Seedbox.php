<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * A network a member has registered as a seedbox, and where it stands.
 *
 * Returned by SeedboxesInterface. An approved seedbox's announces are held to
 * the tracker's seedbox speed cap rather than the ordinary one; a pending or
 * revoked one is not.
 */
final class Seedbox
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REVOKED = 'revoked';

    /**
     * @param  string  $network  CIDR notation, e.g. 203.0.113.7/32 or 2001:db8::/64
     * @param  string  $status  self::PENDING, self::APPROVED or self::REVOKED
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $network,
        public readonly ?string $label,
        public readonly string $status,
        public readonly DateTimeInterface $registeredAt,
        public readonly ?DateTimeInterface $decidedAt = null,
        public readonly ?int $decidedBy = null,
    ) {
        if (! in_array($status, [self::PENDING, self::APPROVED, self::REVOKED], true)) {
            throw new InvalidArgumentException("Unknown seedbox status: {$status}.");
        }
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
