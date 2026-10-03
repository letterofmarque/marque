<?php

declare(strict_types=1);

// #10883: usarrs registers laravel/passkeys' endpoints itself, and only when
// passkeys are on. Off is the default, and then nothing is exposed.

use Illuminate\Support\Facades\Route;

it('registers no passkey endpoints when passkeys are off', function () {
    expect(Route::has('passkey.login'))->toBeFalse()
        ->and(Route::has('passkey.registration-options'))->toBeFalse();

    $this->getJson('/passkeys/login/options')->assertNotFound();
});

it('offers no passkey sign-in on the login page when passkeys are off', function () {
    $this->get(route('login'))->assertDontSee(__('Sign in with a passkey'));
});
