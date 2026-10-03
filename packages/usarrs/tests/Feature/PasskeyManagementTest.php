<?php

declare(strict_types=1);

use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Profile\PasskeyManagement;
use Marque\Usarrs\Tests\TestUser;

// The Livewire component and the model: config gating, the model contract,
// and management (list/delete) once a passkey exists. The WebAuthn ceremony
// itself is PasskeysEnabled/PasskeyCeremonyTest, which drives the real
// endpoints with a software authenticator (#10883). The browser-side script
// still needs a real browser.

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

    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test(PasskeyManagement::class)
        ->call('delete', $passkey->id);

    expect(Passkey::find($passkey->id))->toBeNull();
});

test('removing a passkey asks for a confirmed password first, as the DELETE endpoint does (Job #141 review)', function () {
    config()->set('usarrs.passkeys.enabled', true);

    $passkey = $this->user->passkeys()->create([
        'name' => 'My Yubikey',
        'credential_id' => 'abc123',
        'credential' => ['type' => 'public-key'],
    ]);

    $this->actingAs($this->user);

    Livewire::test(PasskeyManagement::class)
        ->call('delete', $passkey->id)
        ->assertRedirect(route('password.confirm'));

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

test('sends the user to confirm their password and back, when adding a passkey needs it', function () {
    // The page's script calls this when the options endpoint answers 423.
    config()->set('usarrs.passkeys.enabled', true);
    $this->actingAs($this->user);

    $component = Livewire::test(PasskeyManagement::class);
    $page = $component->get('returnTo');

    $component->call('requirePasswordConfirmation')->assertRedirect(route('password.confirm'));

    expect($page)->toStartWith(url('/'))
        ->and(session('url.intended'))->toBe($page);
});

test('the page to return to cannot be changed by the client', function () {
    config()->set('usarrs.passkeys.enabled', true);
    $this->actingAs($this->user);

    expect(fn () => Livewire::test(PasskeyManagement::class)->set('returnTo', 'https://evil.example'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
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
