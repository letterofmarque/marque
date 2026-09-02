<?php

declare(strict_types=1);

use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Profile\TwoFactorSetup;
use Marque\Usarrs\Tests\TestUser;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('two factor setup is unreachable when the feature is off', function () {
    config()->set('usarrs.two_factor.enabled', false);

    $this->actingAs($this->user);

    Livewire::test(TwoFactorSetup::class)
        ->assertForbidden();
});

test('user can enable two factor authentication', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $this->actingAs($this->user);

    Livewire::test(TwoFactorSetup::class)
        ->call('enable');

    $this->user->refresh();

    expect($this->user->two_factor_secret)->not->toBeNull();
    expect($this->user->two_factor_confirmed_at)->toBeNull();
});

test('user can confirm two factor authentication with a valid code', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $this->actingAs($this->user);

    $component = Livewire::test(TwoFactorSetup::class)
        ->call('enable');

    $this->user->refresh();
    $validCode = app(Google2FA::class)
        ->getCurrentOtp(Fortify::currentEncrypter()->decrypt($this->user->two_factor_secret));

    $component->set('code', $validCode)->call('confirm');

    $this->user->refresh();
    expect($this->user->two_factor_confirmed_at)->not->toBeNull();
});

test('confirming with an invalid code fails', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $this->actingAs($this->user);

    Livewire::test(TwoFactorSetup::class)
        ->call('enable')
        ->set('code', '000000')
        ->call('confirm')
        ->assertHasErrors('code');

    $this->user->refresh();
    expect($this->user->two_factor_confirmed_at)->toBeNull();
});

test('user can disable two factor authentication', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $this->actingAs($this->user);
    $this->user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    Livewire::test(TwoFactorSetup::class)
        ->call('disable');

    $this->user->refresh();
    expect($this->user->two_factor_secret)->toBeNull();
    expect($this->user->two_factor_confirmed_at)->toBeNull();
});
