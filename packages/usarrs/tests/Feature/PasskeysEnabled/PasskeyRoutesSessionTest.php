<?php

declare(strict_types=1);

// Build #124 CP #784. laravel/passkeys registers its own routes, with its own
// middleware — and not `auth.session`. So when proving the inbox rotated an
// account's password hash, a squatter's session was dead on every usarrs route
// but could still register a passkey (having confirmed a password beforehand):
// a durable way back in, past the strip.

use Illuminate\Support\Facades\Hash;
use Marque\Usarrs\Tests\TestUser;

it('ends a session whose password hash has rotated on the passkey routes too', function () {
    $user = TestUser::factory()->create();
    $this->actingAs($user);
    $this->get(route('profile.show'))->assertOk(); // the session records its hash
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $user->forceFill(['password' => Hash::make('rotated-elsewhere')])->save();

    $this->getJson('/user/passkeys/options')->assertUnauthorized();
    $this->assertGuest();
});

it('still serves the passkey routes to a session in good standing', function () {
    $user = TestUser::factory()->create();
    $this->actingAs($user);
    $this->get(route('profile.show'))->assertOk();
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->getJson('/user/passkeys/options')->assertOk();
});
