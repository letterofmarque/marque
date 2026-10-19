<?php

declare(strict_types=1);

// Issue #10920. usarrs ended a banned user's web session, but a token on an API
// route kept working: cennad's routes are ['api', 'auth:api'], and the `api`
// group never ran EnsureUserIsActive. A token guard (Passport, Sanctum) has no
// session and no logout, so the refusal there is a 403 and nothing else, and
// it has to run after the guard has authenticated the request.

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Marque\Usarrs\Http\Middleware\EnsureUserIsActive;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    // A token guard of the kind Passport and Sanctum register: a RequestGuard,
    // with no session and no logout(). The bearer token is the user's email.
    Auth::viaRequest('test-token', fn (Request $request) => TestUser::query()->where('email', $request->bearerToken())->first());
    config()->set('auth.guards.token', ['driver' => 'test-token', 'provider' => 'users']);

    Route::middleware(['api', 'auth:token'])->get('/api-probe', fn () => 'ok');
    Route::middleware(['api', 'auth:token'])->post('/api-probe', fn () => 'written');
});

it('refuses a banned user\'s token on an API route', function (string $status, string $message) {
    $user = TestUser::factory()->create(['status' => $status]);

    $this->withToken($user->email)->getJson('/api-probe')
        ->assertForbidden()
        ->assertJson(['message' => $message]);
})->with([
    'banned' => ['banned', 'This account has been banned.'],
    'disabled' => ['disabled', 'This account has been disabled.'],
    'pending' => ['pending', 'This account is awaiting approval.'],
]);

it('refuses a write as well as a read', function () {
    $user = TestUser::factory()->create(['status' => 'banned']);

    $this->withToken($user->email)->postJson('/api-probe')->assertForbidden();
});

it('lets an active user\'s token through', function () {
    $user = TestUser::factory()->create(['status' => 'active']);

    $this->withToken($user->email)->getJson('/api-probe')->assertOk()->assertSee('ok');
});

// The guard still decides who is signed in: with no token, it is a 401, not
// the ban check's business.
it('leaves an unauthenticated request to the guard', function () {
    $this->getJson('/api-probe')->assertUnauthorized();
});

// Unbanning restores access: nothing was revoked, the check runs per request.
it('lets the same token through again once the user is reinstated', function () {
    $user = TestUser::factory()->create(['status' => 'banned']);
    $this->withToken($user->email)->getJson('/api-probe')->assertForbidden();

    $user->forceFill(['status' => 'active'])->save();
    // A request in production gets a fresh guard; the test's app keeps the
    // one that loaded the banned user.
    Auth::forgetGuards();

    $this->withToken($user->email)->getJson('/api-probe')->assertOk();
});

// Laravel's default `api` group holds SubstituteBindings, whose priority
// happens to pull the guard ahead of anything before it. An app's own group
// may not; the check's place after authentication must not depend on that.
it('runs after the guard however the stack lists them', function () {
    Route::middleware([EnsureUserIsActive::class, 'auth:token'])->get('/bare-probe', fn () => 'ok');
    $user = TestUser::factory()->create(['status' => 'banned']);

    $this->withToken($user->email)->getJson('/bare-probe')->assertForbidden();
});
