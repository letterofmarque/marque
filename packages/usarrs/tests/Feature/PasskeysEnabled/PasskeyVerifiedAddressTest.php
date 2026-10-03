<?php

declare(strict_types=1);

// Issue #10879: adding a passkey needs a verified address. Removing one doesn't.

use Marque\Usarrs\Tests\SoftAuthenticator;
use Marque\Usarrs\Tests\TestUser;

it('refuses to start adding a passkey to an unverified account', function () {
    $this->actingAs(TestUser::factory()->unverified()->create())
        ->withSession(['auth.password_confirmed_at' => time()]);

    $this->getJson('/user/passkeys/options')->assertForbidden();
});

it('refuses to store a passkey for an unverified account', function () {
    $this->actingAs(TestUser::factory()->unverified()->create())
        ->withSession(['auth.password_confirmed_at' => time()]);

    $this->postJson('/user/passkeys', [
        'name' => 'Laptop',
        'credential' => (new SoftAuthenticator)->register([
            'user' => ['id' => SoftAuthenticator::encode('x')],
            'challenge' => SoftAuthenticator::encode(random_bytes(32)),
            'rp' => ['id' => 'localhost'],
        ]),
    ])->assertForbidden();
});
