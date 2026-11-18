<?php

declare(strict_types=1);

namespace Marque\Usarrs\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A member signed out every browser but the one they're using (#11154).
 *
 * usarrs ends the other web sessions and remember-me cookies itself. It knows
 * nothing of the app's API tokens (Passport, Sanctum), so an app that issues
 * them listens for this and revokes them.
 */
final class OtherSessionsEnded
{
    public function __construct(public readonly Authenticatable $user) {}
}
