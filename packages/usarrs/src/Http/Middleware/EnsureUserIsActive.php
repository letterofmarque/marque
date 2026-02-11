<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Marque\Usarrs\Enums\UserStatus;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && method_exists($user, 'getAttribute')) {
            $status = $user->getAttribute('status');

            if ($status && $status !== UserStatus::Active->value && $status !== UserStatus::Active) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                abort(403, 'Your account has been suspended.');
            }
        }

        return $next($request);
    }
}
