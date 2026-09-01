<?php

declare(strict_types=1);

namespace Marque\Parley\Exceptions;

use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

/**
 * A user has posted too many times within the configured window
 * (config('parley.rate_limiting'), off by default — see Spec #94).
 *
 * Thrown at the service layer, not the Livewire component, so the limit
 * holds for any caller — a REST endpoint, a queued job, a script — not just
 * whichever UI happens to check first. Mirrors ThreadLockedException's shape
 * and reasoning.
 */
final class TooManyPostsException extends RuntimeException
{
    public static function forUser(Authenticatable $user): self
    {
        return new self(sprintf(
            'User [%s] is posting too quickly.',
            $user->getAuthIdentifier(),
        ));
    }
}
