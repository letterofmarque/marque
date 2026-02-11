<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInviteRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('usarrs.invites.required', false) && ! $request->has('invite')) {
            abort(403, 'Registration requires a valid invite code.');
        }

        return $next($request);
    }
}
