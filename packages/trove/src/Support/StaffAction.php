<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One thing a staff member did: who, what, to which record, why, and what
 * changed.
 *
 * Passed to StaffActionsInterface::record(). The action is namespaced by the
 * package that owns it, `<package>.<subject>.<verb>`, e.g.
 * `usarrs.user.role_changed`, so two packages can't collide and a store can
 * filter by package without a lookup table.
 *
 * `changes` is a small before-and-after of the fields that moved, never the
 * whole record: `['role' => ['from' => 'uploader', 'to' => 'moderator']]`.
 * Values are scalars or null, so any store can keep them as they are.
 */
final class StaffAction
{
    public readonly ?string $reason;

    public readonly DateTimeInterface $at;

    /**
     * @param  int|string  $actorId  the staff member's auth identifier
     * @param  string  $action  `<package>.<subject>.<verb>`, lowercase, e.g. `usarrs.user.banned`
     * @param  string  $subjectType  the subject's morph class, e.g. `user` or `Marque\Trove\Models\Torrent`
     * @param  array<string, array{from: scalar|null, to: scalar|null}>  $changes
     * @param  DateTimeInterface|null  $at  defaults to now
     */
    public function __construct(
        public readonly int|string $actorId,
        public readonly string $action,
        public readonly string $subjectType,
        public readonly int|string $subjectId,
        ?string $reason = null,
        public readonly array $changes = [],
        ?DateTimeInterface $at = null,
    ) {
        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $action) !== 1) {
            throw new InvalidArgumentException(
                "A staff action is named <package>.<subject>.<verb> in lowercase, e.g. usarrs.user.banned: got \"{$action}\".",
            );
        }

        foreach ($changes as $field => $change) {
            if (! is_string($field) || ! is_array($change) || array_keys($change) !== ['from', 'to']) {
                throw new InvalidArgumentException(
                    "A change is ['<field>' => ['from' => ..., 'to' => ...]]: got ".json_encode([$field => $change]).'.',
                );
            }

            foreach ($change as $value) {
                if ($value !== null && ! is_scalar($value)) {
                    throw new InvalidArgumentException("A change's from and to are scalars or null: {$field} has ".get_debug_type($value).'.');
                }
            }
        }

        $reason = $reason === null ? null : trim($reason);

        $this->reason = $reason === '' ? null : $reason;
        $this->at = $at ?? CarbonImmutable::now();
    }

    /**
     * The action, from the actor and the subject model rather than their ids.
     *
     * @param  array<string, array{from: scalar|null, to: scalar|null}>  $changes
     */
    public static function of(
        Authenticatable $actor,
        string $action,
        Model $subject,
        ?string $reason = null,
        array $changes = [],
    ): self {
        return new self(
            actorId: $actor->getAuthIdentifier(),
            action: $action,
            subjectType: $subject->getMorphClass(),
            subjectId: $subject->getKey(),
            reason: $reason,
            changes: $changes,
        );
    }

    /**
     * The package that owns the action: the part before the first dot.
     */
    public function package(): string
    {
        return strstr($this->action, '.', true);
    }
}
