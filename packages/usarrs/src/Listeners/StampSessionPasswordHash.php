<?php

declare(strict_types=1);

namespace Marque\Usarrs\Listeners;

use BadMethodCallException;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;

/**
 * Record the password hash a session signed in under, at the moment it signs in.
 *
 * Laravel's `auth.session` middleware ends a session whose recorded hash no
 * longer matches the user's — how "sign out everywhere else" works, and how
 * usarrs ends a squatter's session when the owner proves the inbox (Build #124
 * CP #776). But it records the hash only the first time a session reaches a
 * route carrying that middleware. A session that signed in and then stayed off
 * those routes until after the hash changed would record the *new* one, and
 * survive.
 *
 * Recording it on every sign-in — password, magic link, OAuth, passkey, any
 * guard login at all — closes that gap. Same key and format the middleware
 * reads.
 */
class StampSessionPasswordHash
{
    public function handle(Login $event): void
    {
        if ($event->guard !== Auth::getDefaultDriver()) {
            return;
        }

        $request = request();
        $password = $event->user->getAuthPassword();

        if (! $request->hasSession() || ! $password) {
            return;
        }

        $guard = Auth::guard($event->guard);

        try {
            $password = $guard->hashPasswordForCookie($password);
        } catch (BadMethodCallException) {
        }

        $request->session()->put('password_hash_'.$event->guard, $password);
    }
}
