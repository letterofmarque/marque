<?php

declare(strict_types=1);

namespace Marque\Usarrs\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Marque\Usarrs\Enums\UserStatus;

/**
 * Refuses a sign-in by an inactive user that didn't come through the login
 * seam (#10857).
 *
 * LoginCompletion refuses them with a message on the login form. This is the
 * backstop for the ways in that never reach it: laravel/passkeys signs in
 * through its own endpoint, and a remember-me cookie re-authenticates with no
 * usarrs code running. Both fire Login.
 */
class RefuseInactiveLogin
{
    public function handle(Login $event): void
    {
        self::refuse($event->user, $event->guard);
    }

    /**
     * Sign the user out and stop the request, if they may not be signed in.
     */
    public static function refuse(object $user, ?string $guard = null): void
    {
        $refusal = UserStatus::refusalFor($user);

        if ($refusal === null) {
            return;
        }

        Auth::guard($guard)->logout();

        if (request()->hasSession()) {
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        abort(403, $refusal);
    }
}
