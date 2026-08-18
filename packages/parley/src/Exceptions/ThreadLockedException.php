<?php

declare(strict_types=1);

namespace Marque\Parley\Exceptions;

use Marque\Parley\Models\Thread;
use RuntimeException;

/**
 * A moderator locked this thread; new posts are refused.
 *
 * Thrown at the service layer rather than left as a UI-only restriction, so a
 * locked thread stays closed against anything that calls the service directly
 * — a REST endpoint, a queued job, a script — not just the component that
 * happens to grey out its own submit button.
 */
final class ThreadLockedException extends RuntimeException
{
    public static function forThread(Thread $thread): self
    {
        return new self(sprintf('Thread [%d] is locked.', $thread->getKey()));
    }
}
