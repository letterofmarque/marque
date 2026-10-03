<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Marque\Usarrs\Listeners\RefuseInactiveLogin;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who is no longer active (#10857).
 *
 * Pushed onto the whole `web` group, not just usarrs' routes: a ban that only
 * held on /profile would leave the user browsing and downloading everywhere
 * else. Livewire's update endpoint runs in that group too, so a tab opened
 * before the ban can't keep acting.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            RefuseInactiveLogin::refuse($user);
        }

        return $next($request);
    }
}
