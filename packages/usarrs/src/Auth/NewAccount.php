<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Event;

/**
 * Announces a new account, from every place one is made (#10840).
 *
 * Password registration used to say nothing, which meant no Registered event
 * for the app to hook and no verification email. The OAuth callback sent the
 * mail directly but fired no event. Both now come through here, so they can't
 * drift apart again.
 *
 * Exactly one mail: a stock Laravel 11+ app registers
 * SendEmailVerificationNotification on Registered for itself, and sending as
 * well would deliver two. So the mail is sent here only when that listener
 * isn't registered.
 */
class NewAccount
{
    public static function announce(Authenticatable $user): void
    {
        event(new Registered($user));

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail() && ! self::appSendsVerification()) {
            $user->sendEmailVerificationNotification();
        }
    }

    private static function appSendsVerification(): bool
    {
        $listeners = Event::getRawListeners()[Registered::class] ?? [];

        foreach ($listeners as $listener) {
            $class = is_array($listener) ? ($listener[0] ?? null) : $listener;

            if ($class === SendEmailVerificationNotification::class) {
                return true;
            }
        }

        return false;
    }
}
