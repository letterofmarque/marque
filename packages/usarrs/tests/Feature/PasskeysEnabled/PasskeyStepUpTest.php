<?php

declare(strict_types=1);

// Job #355 step 1. laravel/passkeys' confirm endpoint already marks the session
// confirmed, but the confirm page never offered it, so a member with a passkey
// and no usable password still hit a dead end.

use Illuminate\Support\Facades\Route;
use Marque\Usarrs\Tests\SoftAuthenticator;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'password.confirm'])
        ->get('/test-confirm-only', fn () => 'ok');
});

function enrolPasskey(object $test, TestUser $user, SoftAuthenticator $authenticator): void
{
    $test->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $options = $test->getJson('/user/passkeys/options')->assertOk()->json('options');
    $test->postJson('/user/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($options)])->assertSuccessful();
    $test->flushSession();
}

it('offers a passkey on the confirm page to a member who has one', function () {
    $user = TestUser::factory()->create();
    enrolPasskey($this, $user, new SoftAuthenticator);

    $this->actingAs($user)->get(route('password.confirm'))
        ->assertOk()
        ->assertSee(__('Confirm with a passkey'));
});

it('does not offer a passkey to a member without one', function () {
    $user = TestUser::factory()->create();

    $this->actingAs($user)->get(route('password.confirm'))
        ->assertOk()
        ->assertDontSee(__('Confirm with a passkey'));
});

it('lets a socialite member with a passkey pass password.confirm with it', function () {
    config(['usarrs.auth_driver' => 'socialite']);
    $user = TestUser::factory()->create();
    $authenticator = new SoftAuthenticator;
    enrolPasskey($this, $user, $authenticator);

    $this->actingAs($user)->get('/test-confirm-only')->assertRedirect(route('password.confirm'));

    $options = $this->getJson(route('passkey.confirm-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.confirm'), ['credential' => $authenticator->signIn($options)])
        ->assertOk()
        ->assertJson(['redirect' => url('/test-confirm-only')]);

    $this->get('/test-confirm-only')->assertOk();
});
