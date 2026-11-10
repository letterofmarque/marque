<?php

declare(strict_types=1);

// Job #355 step 1. Under socialite mode an account's password is random and
// unknown, so password.confirm was a dead end. A member confirms by signing in
// again with a provider already connected to *their* account: an OAuth round
// trip started from the confirm page, whose callback accepts only an identity
// linked to the signed-in member.

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('usarrs.socialite_providers', ['github', 'gitlab']);

    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);

    $this->me = TestUser::factory()->create(['email' => 'me@example.com']);
    SocialAccount::forceCreate(['user_id' => $this->me->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-me']);

    Route::middleware(['web', 'auth', 'password.confirm'])
        ->get('/test-confirm-only', fn () => 'ok');
});

it('offers each connected provider on the confirm page, and only those', function () {
    $this->actingAs($this->me)->get(route('password.confirm'))
        ->assertOk()
        ->assertSee(route('socialite.confirm', 'github'))
        ->assertDontSee(route('socialite.confirm', 'gitlab'));
});

it('lets a socialite member pass password.confirm by signing in again with their provider', function () {
    $this->actingAs($this->me)->get('/test-confirm-only')->assertRedirect(route('password.confirm'));

    $this->post(route('socialite.confirm', 'github'))->assertRedirect('https://oauth.example/github/authorize');

    $this->oauth->asserts('github', 'gh-me', 'me@example.com');
    $this->get(route('socialite.callback', 'github'))->assertRedirect(url('/test-confirm-only'));

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
    $this->get('/test-confirm-only')->assertOk();
});

it('refuses an identity that isn\'t connected to the signed-in member', function (string $id) {
    // A stolen session's browser is signed in to the thief's provider account,
    // not the owner's.
    $someoneElse = TestUser::factory()->create();
    SocialAccount::forceCreate(['user_id' => $someoneElse->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-else']);

    $this->actingAs($this->me)->post(route('socialite.confirm', 'github'));

    $this->oauth->asserts('github', $id, 'me@example.com');
    $this->get(route('socialite.callback', 'github'))->assertRedirect(route('password.confirm'));

    expect(session('auth.password_confirmed_at'))->toBeNull();
    $this->assertAuthenticatedAs($this->me);
})->with([
    'linked to someone else' => ['gh-else'],
    'linked to nobody' => ['gh-new'],
]);

it('connects nothing while confirming', function () {
    // The callback means "connect" to a signed-in member otherwise. An
    // unlinked identity returning from a confirmation must not be connected.
    $this->actingAs($this->me)->post(route('socialite.confirm', 'gitlab'));

    $this->oauth->asserts('gitlab', 'gl-new', 'me@example.com');
    $this->get(route('socialite.callback', 'gitlab'));

    expect(SocialAccount::query()->where('provider', 'gitlab')->exists())->toBeFalse();
});

it('starts a confirmation only by POST', function () {
    $this->actingAs($this->me)->get(url('auth/github/confirm'))->assertMethodNotAllowed();
});

it('starts a confirmation only for a signed-in member', function () {
    $this->post(route('socialite.confirm', 'github'))->assertRedirect(route('login'));
});

it('reads a confirmation once: the next round trip is ordinary again', function () {
    $this->actingAs($this->me)->post(route('socialite.confirm', 'github'));
    $this->oauth->asserts('github', 'gh-me', 'me@example.com');
    $this->get(route('socialite.callback', 'github'));

    expect(session()->has('usarrs.oauth.intent'))->toBeFalse();
});
