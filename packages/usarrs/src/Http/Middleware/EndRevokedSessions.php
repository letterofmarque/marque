<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Marque\Usarrs\Auth\SessionEpoch;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session the member signed out from elsewhere (#11154).
 *
 * On the whole `web` group, like EnsureUserIsActive, so the session ends on
 * its next request to any page, and Livewire's update endpoint (in the same
 * group) refuses a tab opened before. It ends it the way Laravel's
 * auth.session does: this device only (no remember-token cycling, which would
 * undo the member's own cookie), the session flushed, unauthenticated.
 */
class EndRevokedSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard();

        if ($request->hasSession() && $guard instanceof SessionGuard && ($user = $guard->user()) !== null) {
            if (SessionEpoch::revoked($request->session(), $user)) {
                $guard->logoutCurrentDevice();
                $request->session()->flush();

                throw new AuthenticationException('Unauthenticated.', [Auth::getDefaultDriver()]);
            }

            if (! $request->session()->has(SessionEpoch::SESSION_KEY)) {
                SessionEpoch::stamp($user);
            }
        }

        return $next($request);
    }
}
