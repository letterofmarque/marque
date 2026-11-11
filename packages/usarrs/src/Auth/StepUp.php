<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

/**
 * Has this session confirmed it's the member recently? The same question
 * Laravel's password.confirm middleware asks, for the places that can't sit
 * behind it: a Livewire action, a POST whose redirect back would be a GET, a
 * callback at the end of a round trip (Job #355).
 *
 * Every way of confirming (Livewire\Auth\PasswordConfirm) ends in the session
 * mark this reads.
 */
final class StepUp
{
    public static function recent(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);
    }
}
