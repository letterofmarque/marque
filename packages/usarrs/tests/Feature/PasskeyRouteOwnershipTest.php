<?php

declare(strict_types=1);

// #10883 and the Job #141 review. usarrs registers the passkey endpoints
// itself, so it tells laravel/passkeys not to, but only when usarrs manages
// auth. With manage_auth off, routes/auth.php isn't loaded at all, and an app
// relying on laravel/passkeys' own routes lost them.

use Laravel\Passkeys\Passkeys;
use Marque\Usarrs\UsarrsServiceProvider;

function passkeysRegisterOwnRoutesAfterUsarrs(bool $manageAuth, bool $passkeys): bool
{
    config()->set('usarrs.manage_auth', $manageAuth);
    config()->set('usarrs.passkeys.enabled', $passkeys);
    (new ReflectionProperty(Passkeys::class, 'registersRoutes'))->setValue(null, true);

    (new UsarrsServiceProvider(app()))->register();

    return Passkeys::shouldRegisterRoutes();
}

it('stops laravel/passkeys registering routes when usarrs manages auth and registers them itself', function () {
    expect(passkeysRegisterOwnRoutesAfterUsarrs(manageAuth: true, passkeys: true))->toBeFalse();
});

it('leaves laravel/passkeys\' own routes alone when manage_auth is off and passkeys are on', function () {
    expect(passkeysRegisterOwnRoutesAfterUsarrs(manageAuth: false, passkeys: true))->toBeTrue();
});

it('still exposes nothing when passkeys are off', function () {
    expect(passkeysRegisterOwnRoutesAfterUsarrs(manageAuth: false, passkeys: false))->toBeFalse();
});
