<?php

declare(strict_types=1);

// Job #355, from its independent check. Turning two-factor on or off,
// regenerating recovery codes, and seeing them took no step-up: a session that
// wasn't the member's could disable it, read the codes, or turn it on with its
// own authenticator and lock the owner out behind a challenge only it could
// answer. Every member can step up now (step 1), so all of it asks.

use Livewire\Livewire;
use Marque\Usarrs\Livewire\Profile\TwoFactorSetup;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    config()->set('usarrs.two_factor.enabled', true);
    $this->user = TestUser::factory()->create();
});

function withTwoFactor(TestUser $user): void
{
    $user->forceFill([
        'two_factor_secret' => encrypt('SECRETSECRETSECR'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one-aaaa', 'code-two-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
}

it('sends an unconfirmed member to confirm before each change, and changes nothing', function (string $action) {
    withTwoFactor($this->user);
    $before = $this->user->fresh()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);

    Livewire::actingAs($this->user)->test(TwoFactorSetup::class)
        ->call($action)
        ->assertRedirect(route('password.confirm'));

    expect($this->user->fresh()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->toBe($before);
})->with(['disable', 'regenerateRecoveryCodes']);

it('sends an unconfirmed member to confirm before turning it on', function () {
    Livewire::actingAs($this->user)->test(TwoFactorSetup::class)
        ->call('enable')
        ->assertRedirect(route('password.confirm'));

    expect($this->user->fresh()->two_factor_secret)->toBeNull();
});

it('shows recovery codes only to a session that confirmed recently', function () {
    withTwoFactor($this->user);

    Livewire::actingAs($this->user)->test(TwoFactorSetup::class)->assertDontSee('code-one-aaaa');

    session()->put('auth.password_confirmed_at', time());
    Livewire::actingAs($this->user)->test(TwoFactorSetup::class)->assertSee('code-one-aaaa');
});
