<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Confirming it's you through your verified inbox (Job #355): the step-up
 * every member has, whatever the auth driver, including a socialite or
 * magic-link member who has no password to type.
 *
 * Three moves, on up to two devices:
 *
 * 1. The session asking for confirmation requests a link. A pending approval
 *    is held here, server-side, under a random token; the token goes into the
 *    mailed (signed, expiring) URL and into that session.
 * 2. Whoever opens the link approves it, deliberately, on the page it opens
 *    (Livewire\Auth\ConfirmByEmail). Opening it does nothing, because mail
 *    scanners follow links. The page can be on any device: reading mail on a
 *    phone confirms the laptop that asked.
 * 3. The session that asked claims the approval: only that session (it holds
 *    the token), only for that member, and only once.
 *
 * The approval is for the session that asked, never "any session of this
 * member": a thief's session that asks gets a link in the owner's inbox,
 * naming the browser that asked (agent), and nothing without the owner's
 * click.
 */
final class EmailStepUp
{
    public const CACHE_PREFIX = 'usarrs.step-up.';

    public const SESSION_KEY = 'usarrs.step-up.token';

    public const MINUTES = 15;

    /**
     * Hold a pending approval for this session and return the URL to mail.
     */
    public static function request(Authenticatable $user, ?string $agent): string
    {
        $token = Str::random(40);
        $expires = now()->addMinutes(self::MINUTES);

        Cache::put(self::CACHE_PREFIX.$token, [
            'user' => $user->getAuthIdentifier(),
            'approved' => false,
            'agent' => Str::limit((string) $agent, 200),
            'expires' => $expires->getTimestamp(),
        ], $expires);

        session()->put(self::SESSION_KEY, $token);

        return URL::temporarySignedRoute('password.confirm.email', $expires, ['token' => $token]);
    }

    /**
     * The pending approval behind a token, or null if it was used or expired.
     *
     * @return array{user: mixed, approved: bool, agent: string, expires: int}|null
     */
    public static function pending(string $token): ?array
    {
        $pending = Cache::get(self::CACHE_PREFIX.$token);

        return is_array($pending) ? $pending : null;
    }

    /**
     * Approve a pending confirmation. False if there's nothing to approve.
     */
    public static function approve(string $token): bool
    {
        $pending = self::pending($token);

        if ($pending === null || $pending['approved']) {
            return false;
        }

        Cache::put(self::CACHE_PREFIX.$token, [...$pending, 'approved' => true], now()->setTimestamp($pending['expires']));

        return true;
    }

    /**
     * Claim an approval for this session and this member. Consumes it: true
     * once, then false.
     */
    public static function claim(Authenticatable $user): bool
    {
        $token = session(self::SESSION_KEY);

        if (! is_string($token)) {
            return false;
        }

        $pending = self::pending($token);

        if ($pending === null || ! $pending['approved'] || (string) $pending['user'] !== (string) $user->getAuthIdentifier()) {
            return false;
        }

        // pull: whichever of two racing claims gets it, the other finds nothing.
        if (Cache::pull(self::CACHE_PREFIX.$token) === null) {
            return false;
        }

        session()->forget(self::SESSION_KEY);

        return true;
    }
}
