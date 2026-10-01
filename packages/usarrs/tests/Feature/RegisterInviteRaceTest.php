<?php

declare(strict_types=1);

// Build #124 CP #763. /register checked the invite, created the account, then
// redeemed unconditionally — so N registrations racing on one invite made N
// accounts. Now the account and the redemption commit together or not at all.

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Tests\TestUser;

it('makes no account when the invite is redeemed by someone else mid-request', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);
    $invite = app(InviteServiceInterface::class)->create(TestUser::factory()->create());
    $winner = TestUser::factory()->create();

    TestUser::creating(function (TestUser $user) use ($invite, $winner) {
        if ($user->email === 'racer@example.com') {
            DB::table('invites')->where('id', $invite->id)->update(['status' => 'used', 'used_by_id' => $winner->getKey()]);
        }
    });

    Livewire::withQueryParams(['invite' => $invite->code])
        ->test(Register::class)
        ->set('name', 'Racer')
        ->set('email', 'racer@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasErrors('invite');

    // (The winner's write is undone here too — in this test it runs inside the
    // losing request's transaction. A real competitor commits on its own.)
    expect(TestUser::where('email', 'racer@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

// Build #124 CP #777: the same case-sensitivity hole on /register.
it('refuses to register an address an account uses in a different case', function () {
    TestUser::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(Register::class)
        ->set('name', 'Twin')
        ->set('email', 'TAKEN@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasErrors('email');

    expect(TestUser::count())->toBe(1);
});
