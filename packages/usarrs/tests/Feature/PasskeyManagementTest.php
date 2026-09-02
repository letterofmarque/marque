<?php

declare(strict_types=1);

use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Profile\PasskeyManagement;
use Marque\Usarrs\Tests\TestUser;

// The actual WebAuthn ceremony (navigator.credentials.create/get, the
// browser<->authenticator cryptographic exchange) cannot be exercised
// headlessly — GenerateRegistrationOptions/StorePasskey/VerifyPasskey all
// require a real Webauthn\PublicKeyCredential produced by a browser and a
// real authenticator (Touch ID, Windows Hello, a hardware key). There is no
// meaningful way to fake a valid attestation in a Pest test without
// reimplementing an authenticator. What's covered here is everything around
// that boundary: config gating, the model contract, and passkey management
// (list/rename/delete) once a passkey already exists in the database — the
// registration/login ceremony itself needs a manual or Playwright pass
// against a real browser, tracked as a gap, not silently skipped.

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('passkey management is unreachable when the feature is off', function () {
    config()->set('usarrs.passkeys.enabled', false);

    $this->actingAs($this->user);

    Livewire::test(PasskeyManagement::class)
        ->assertForbidden();
});

test('user with no passkeys sees an empty list', function () {
    config()->set('usarrs.passkeys.enabled', true);

    $this->actingAs($this->user);

    Livewire::test(PasskeyManagement::class)
        ->assertViewHas('passkeys', function ($passkeys) {
            return $passkeys->isEmpty();
        });
});

test('user can see their existing passkeys', function () {
    config()->set('usarrs.passkeys.enabled', true);

    $this->user->passkeys()->create([
        'name' => 'My Yubikey',
        'credential_id' => 'abc123',
        'credential' => ['type' => 'public-key'],
    ]);

    $this->actingAs($this->user);

    Livewire::test(PasskeyManagement::class)
        ->assertSee('My Yubikey');
});

test('user can delete their own passkey', function () {
    config()->set('usarrs.passkeys.enabled', true);

    $passkey = $this->user->passkeys()->create([
        'name' => 'My Yubikey',
        'credential_id' => 'abc123',
        'credential' => ['type' => 'public-key'],
    ]);

    $this->actingAs($this->user);

    Livewire::test(PasskeyManagement::class)
        ->call('delete', $passkey->id);

    expect(Passkey::find($passkey->id))->toBeNull();
});

test('user cannot delete another users passkey', function () {
    config()->set('usarrs.passkeys.enabled', true);

    $otherUser = TestUser::factory()->create();
    $passkey = $otherUser->passkeys()->create([
        'name' => 'Not Yours',
        'credential_id' => 'xyz789',
        'credential' => ['type' => 'public-key'],
    ]);

    $this->actingAs($this->user);

    Livewire::test(PasskeyManagement::class)
        ->call('delete', $passkey->id)
        ->assertForbidden();

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

test('test user model satisfies the PasskeyUser contract', function () {
    expect($this->user)->toBeInstanceOf(PasskeyUser::class);
    expect($this->user->hasPasskeysEnabled())->toBeFalse();
    expect($this->user->getPasskeyUserHandle())->toBeString();
    expect($this->user->getPasskeyDisplayName())->toBe($this->user->name);
    expect($this->user->getPasskeyUsername())->toBe($this->user->email);
});
