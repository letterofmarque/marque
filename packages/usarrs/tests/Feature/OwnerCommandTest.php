<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Usarrs\Tests\TestUser;

/**
 * Only an owner grants Admin, so a tracker with no owner can never make
 * another admin. The console is how the first owner is set: whoever runs it
 * already controls the server.
 */
it('makes the user with that address an owner', function () {
    $admin = TestUser::factory()->admin()->create(['email' => 'boss@example.com']);

    $this->artisan('usarrs:owner', ['email' => 'boss@example.com'])
        ->expectsOutputToContain('boss@example.com is now an owner')
        ->assertSuccessful();

    expect($admin->fresh()->role)->toBe(Role::Owner);
});

it('says so when the user is already an owner', function () {
    TestUser::factory()->owner()->create(['email' => 'boss@example.com']);

    $this->artisan('usarrs:owner', ['email' => 'boss@example.com'])
        ->expectsOutputToContain('already an owner')
        ->assertSuccessful();
});

it('fails for an address nobody has', function () {
    $this->artisan('usarrs:owner', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user has the address nobody@example.com')
        ->assertFailed();
});
