<?php

declare(strict_types=1);

namespace Marque\Bosun\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Marque\Bosun\Models\StaffPresence;
use Marque\Trove\Enums\Role;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notes when a staff member was last on the site, for the Staff screen.
 *
 * Staff only (bosun.staff_role and above): a member's activity is never
 * tracked. At most once per interval, so a staff member browsing costs one
 * cache hit per request and one write every few minutes.
 */
class StampStaffPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->stamp($request);

        return $next($request);
    }

    private function stamp(Request $request): void
    {
        if (! config('bosun.presence.enabled', true)) {
            return;
        }

        $user = $request->user();
        $floor = Role::tryFrom((string) config('bosun.staff_role', 'moderator')) ?? Role::Moderator;

        if ($user === null || ! method_exists($user, 'hasRoleAtLeast') || ! $user->hasRoleAtLeast($floor)) {
            return;
        }

        $minutes = max(1, (int) config('bosun.presence.interval_minutes', 5));

        // Cache::add() only succeeds when the key isn't there, so it is the
        // throttle. A cache that's down shouldn't cost the page: stamp anyway.
        try {
            if (! Cache::add('bosun.presence.'.$user->getAuthIdentifier(), true, now()->addMinutes($minutes))) {
                return;
            }
        } catch (Throwable) {
            // Fall through and stamp.
        }

        try {
            StaffPresence::query()->upsert(
                [['user_id' => $user->getAuthIdentifier(), 'last_seen_at' => now()]],
                ['user_id'],
                ['last_seen_at'],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
