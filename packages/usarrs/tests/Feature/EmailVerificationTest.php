<?php

declare(strict_types=1);

// job #10602 Gap 7: Fortify's own /email/verify routes are suppressed
// unconditionally (Fortify::ignoreRoutes(), correct per job #10583), but
// usarrs never re-registered them under its own controller — leaving
// Laravel's stock 'verified' middleware permanently unsatisfiable (a
// lockout, not a security hole). See Spec #96.

use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Marque\Usarrs\Tests\TestUser;

test('a route gated by verified middleware redirects an unverified user to verification.notice', function () {
    $user = TestUser::factory()->unverified()->create();

    Route::middleware(['web', 'auth', 'verified'])
        ->get('/test-verified-only', fn () => 'ok');

    $this->actingAs($user)
        ->get('/test-verified-only')
        ->assertRedirect(route('verification.notice'));
});

test('verification.notice page is reachable and shows the resend form', function () {
    $user = TestUser::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertSee(route('verification.send'), escape: false);
});

test('a valid signed verification link marks the user verified and fires Verified', function () {
    Event::fake([Verified::class]);

    $user = TestUser::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

test('a verified user hitting the signed link again does not re-fire Verified', function () {
    Event::fake([Verified::class]);

    $user = TestUser::factory()->create(); // already verified by default

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect();

    Event::assertNotDispatched(Verified::class);
});

test('a tampered signature is rejected', function () {
    $user = TestUser::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $tampered = $url.'&tampered=1';

    $this->actingAs($user)->get($tampered)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the id/hash must match the authenticated user, even with a valid signature', function () {
    $user = TestUser::factory()->unverified()->create();
    $otherUser = TestUser::factory()->unverified()->create();

    // Signed correctly for $user, but visited while authenticated as
    // $otherUser — the signature alone isn't enough, the route params must
    // also match request()->user(), same as Fortify's own VerifyEmailRequest.
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->actingAs($otherUser)->get($url)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    expect($otherUser->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an expired signed link is rejected', function () {
    $user = TestUser::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->subMinutes(1), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->actingAs($user)->get($url)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('resending the verification notification flashes a status message', function () {
    Notification::fake();

    $user = TestUser::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect();

    Notification::assertSentTo($user, VerifyEmail::class);
});

// The headline regression: this is the exact internal-inconsistency job
// #10602 found. config('usarrs.admin_middleware') ships defaulting to
// ['web', 'auth', 'verified'] — the package's own admin panel already
// depended on 'verified' working. Uses the ACTUAL shipped config default,
// not a hand-rolled route, so this genuinely proves the fix rather than
// merely proving the middleware works in the abstract.
test('admin_middleware default is actually satisfiable end-to-end for a newly-registered user', function () {
    $newUser = TestUser::factory()->unverified()->create();

    Route::middleware(config('usarrs.admin_middleware'))
        ->get('/test-admin-only', fn () => 'admin area');

    // Step 1: locked out, exactly as job #10602 found — but now redirected
    // to a real page, not left with nowhere to go.
    $this->actingAs($newUser)
        ->get('/test-admin-only')
        ->assertRedirect(route('verification.notice'));

    // Step 2: the user follows their verification email's link.
    $verifyUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $newUser->getKey(),
        'hash' => sha1($newUser->getEmailForVerification()),
    ]);
    $this->actingAs($newUser)->get($verifyUrl);

    expect($newUser->fresh()->hasVerifiedEmail())->toBeTrue();

    // Step 3: the same admin_middleware-gated route now passes through.
    $this->actingAs($newUser->fresh())
        ->get('/test-admin-only')
        ->assertOk()
        ->assertSee('admin area');
});
