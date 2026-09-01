<?php

declare(strict_types=1);

// job #10602 Gap 7 (continued): Fortify's own /user/confirm-password route
// is suppressed unconditionally, and usarrs never re-registered it —
// leaving Laravel's stock 'password.confirm' middleware permanently
// unsatisfiable. See Spec #96.

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\PasswordConfirm;
use Marque\Usarrs\Tests\TestUser;

test('a route gated by password.confirm middleware redirects an unconfirmed session', function () {
    $user = TestUser::factory()->create();

    Route::middleware(['web', 'auth', 'password.confirm'])
        ->get('/test-confirm-only', fn () => 'ok');

    $this->actingAs($user)
        ->get('/test-confirm-only')
        ->assertRedirect(route('password.confirm'));
});

test('the password.confirm page is reachable', function () {
    $user = TestUser::factory()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk();
});

test('confirming with the correct password sets the session key and unlocks the gated route', function () {
    $user = TestUser::factory()->create(['password' => bcrypt('correct-password')]);

    Route::middleware(['web', 'auth', 'password.confirm'])
        ->get('/test-confirm-only', fn () => 'ok');

    Livewire::actingAs($user)
        ->test(PasswordConfirm::class)
        ->set('password', 'correct-password')
        ->call('confirm')
        ->assertRedirect('/');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();

    $this->actingAs($user)
        ->get('/test-confirm-only')
        ->assertOk();
});

test('confirming with the wrong password fails validation and does not set the session key', function () {
    $user = TestUser::factory()->create(['password' => bcrypt('correct-password')]);

    Livewire::actingAs($user)
        ->test(PasswordConfirm::class)
        ->set('password', 'wrong-password')
        ->call('confirm')
        ->assertHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});
