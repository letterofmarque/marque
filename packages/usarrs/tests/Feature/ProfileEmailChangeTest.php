<?php

declare(strict_types=1);

// Build #124 CP #776. Changing the email on /profile/edit kept the account's
// verification. So a squatter holding an unverified account under someone
// else's address could switch it to their own inbox, verify that, and switch
// back: the account then read as verified under the victim's address, and
// confirming an OAuth connection from the victim's inbox never stripped it
// (ConfirmOAuthLink::stripUnprovenAccount()). A verified address has to mean *this*
// address was proven.

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Profile\Edit;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    $this->user = TestUser::factory()->create(['email' => 'old@example.com']);
});

it('un-verifies the account when its email changes, and sends the new address a verification mail', function () {
    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('email', 'new@example.com')
        ->call('save')
        ->assertHasNoErrors();

    $user = $this->user->fresh();
    expect($user->email)->toBe('new@example.com')
        ->and($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('keeps the verification when the email is saved unchanged', function () {
    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('name', 'Renamed')
        ->call('save');

    expect($this->user->fresh()->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});

it('treats a change of case as no change', function () {
    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('email', 'OLD@example.com')
        ->call('save');

    expect($this->user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses an address another account uses, instead of failing', function () {
    TestUser::factory()->create(['email' => 'taken@example.com']);

    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('email', 'taken@example.com')
        ->call('save')
        ->assertHasErrors('email');

    expect($this->user->fresh()->email)->toBe('old@example.com');
});

// PostgreSQL and SQLite compare case-sensitively, so `Victim@example.com` passed
// a plain unique rule beside `victim@example.com` — and the OAuth callback,
// matching case-insensitively, could then route the victim's own confirmation
// to the wrong account (CP #777).
it('refuses an address another account uses in a different case', function () {
    TestUser::factory()->create(['email' => 'taken@example.com']);

    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('email', 'Taken@Example.com')
        ->call('save')
        ->assertHasErrors('email');

    expect($this->user->fresh()->email)->toBe('old@example.com');
});

// CP #784: a soft-deleted account still holds its address in the unique index,
// so a rule that skipped it (the model's global scopes) let the save through to
// a constraint violation — a 500.
it('counts an address held by a soft-deleted account as taken', function () {
    DB::table('users')->insert([
        'name' => 'Gone', 'email' => 'gone@example.com', 'password' => 'x',
        'role' => 'user', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    TestUser::addGlobalScope('hide-gone', fn ($q) => $q->where('email', '!=', 'gone@example.com'));

    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('email', 'gone@example.com')
        ->call('save')
        ->assertHasErrors('email');
});

// SQLite's lower() folds ASCII only, and PostgreSQL's depends on locale, while
// PHP's mb_strtolower() folds everything — so comparing lower(column) to a
// PHP-lowered value never matched a non-ASCII address. Fold both sides in SQL.
it('matches a non-ASCII address in a different case the way the database folds it', function () {
    TestUser::factory()->create(['email' => 'ÄRGER@example.com']);

    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('email', 'ÄRGER@EXAMPLE.COM')
        ->call('save')
        ->assertHasErrors('email');
});
