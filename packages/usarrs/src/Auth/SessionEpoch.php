<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Marque\Usarrs\Events\OtherSessionsEnded;

/**
 * "Sign out every other browser", for every member (#11154).
 *
 * Laravel's own way, logoutOtherDevices(), re-hashes the password from the
 * plaintext the member types, so it needs one: out of reach for a member who
 * signs in by magic link, OAuth or passkey and may never have set one. And
 * rotating the hash to something random instead would destroy any password
 * they had set. So
 * each session is stamped with the member's `session_epoch` when it signs in.
 * Ending the others moves the epoch on and restamps this session; the
 * EndRevokedSessions middleware ends any session whose stamp no longer
 * matches. The password is never touched.
 */
final class SessionEpoch
{
    public const SESSION_KEY = 'usarrs.session_epoch';

    public static function of(Authenticatable $user): int
    {
        return $user instanceof Model ? (int) $user->getAttribute('session_epoch') : 0;
    }

    /** Record $user's current epoch as the one this session holds. */
    public static function stamp(Authenticatable $user): void
    {
        if (app()->bound('session.store')) {
            app('session.store')->put(self::SESSION_KEY, self::of($user));
        }
    }

    /**
     * Has this session been ended from elsewhere? A stamp that doesn't match
     * has. A session with no stamp counts as current only while the epoch is
     * still 0 (nothing has ever been ended, or it predates this feature); once
     * the epoch has moved, an unstamped session can't show it's current.
     */
    public static function revoked(Session $session, Authenticatable $user): bool
    {
        $epoch = self::of($user);

        if (! $session->has(self::SESSION_KEY)) {
            return $epoch !== 0;
        }

        return (int) $session->get(self::SESSION_KEY) !== $epoch;
    }

    /**
     * End every session of $user's but this one. The caller has checked for
     * a recent step-up.
     */
    public static function endOthers(Authenticatable&Model $user): void
    {
        $guard = Auth::guard();

        // Another device's remember-me cookie would sign it straight back in,
        // into a fresh session stamped with the new epoch. A new token kills
        // those cookies; this device's, if it has one, is re-issued.
        if ($guard instanceof SessionGuard) {
            $guard->usarrsCycleRememberToken();
        }

        $user->newQuery()->whereKey($user->getKey())->increment('session_epoch');

        $user->setAttribute('session_epoch', (int) $user->newQuery()->whereKey($user->getKey())->value('session_epoch'));
        $user->syncOriginalAttribute('session_epoch');

        self::stamp($user);

        event(new OtherSessionsEnded($user));
    }

    /**
     * Teach SessionGuard to cycle the remember token the way its own
     * logoutOtherDevices() does. Both steps are protected methods; a macro
     * runs bound to the guard, so it can reach them.
     */
    public static function registerGuardMacro(): void
    {
        if (SessionGuard::hasMacro('usarrsCycleRememberToken')) {
            return;
        }

        SessionGuard::macro('usarrsCycleRememberToken', function (): void {
            /** @var SessionGuard $this */
            $user = $this->user();

            if ($user === null) {
                return;
            }

            $this->cycleRememberToken($user);

            if ($this->recaller()) {
                $this->queueRecallerCookie($user);
            }
        });
    }
}
