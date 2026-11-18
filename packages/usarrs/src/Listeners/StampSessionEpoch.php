<?php

declare(strict_types=1);

namespace Marque\Usarrs\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Marque\Usarrs\Auth\SessionEpoch;

/**
 * Stamp every new session with the member's session epoch (#11154), however
 * it signed in: password, magic link, OAuth, passkey or a remember-me cookie.
 */
class StampSessionEpoch
{
    public function handle(Login $event): void
    {
        if ($event->guard === Auth::getDefaultDriver()) {
            SessionEpoch::stamp($event->user);
        }
    }
}
