<?php

declare(strict_types=1);

// Spec #142 criterion 4: one answer to "may an account be created?", shared by
// /register and the OAuth callback. OAuth only exists under socialite mode, so
// the invite_only case can't be reached through the callback — it is proven
// here, against the rules themselves, as well as through /register.

use Marque\Usarrs\Auth\RegistrationRules;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Tests\TestUser;

it('under invite_only, refuses an account without a valid invite and allows one with it (#10801)', function () {
    config()->set('usarrs.auth_driver', 'invite_only');
    $invite = app(InviteServiceInterface::class)->create(TestUser::factory()->create());

    $rules = app(RegistrationRules::class);

    expect($rules->refusal(null))->not->toBeNull()
        ->and($rules->refusal('nonsense'))->not->toBeNull()
        ->and($rules->refusal($invite->code))->toBeNull();
});

it('allows an account under socialite with invites off', function () {
    config()->set('usarrs.auth_driver', 'socialite');

    expect(app(RegistrationRules::class)->refusal(null))->toBeNull();
});

it('requires a valid invite when invites are required', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);
    $invite = app(InviteServiceInterface::class)->create(TestUser::factory()->create());

    $rules = app(RegistrationRules::class);

    expect($rules->refusal(null))->not->toBeNull()
        ->and($rules->refusal('nonsense'))->not->toBeNull()
        ->and($rules->refusal($invite->code))->toBeNull();
});
