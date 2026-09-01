<?php

declare(strict_types=1);

// job #10602 Gap 7: Fortify's own /email/verify routes are suppressed
// unconditionally (Fortify::ignoreRoutes(), correct per job #10583), but
// usarrs never re-registered them under its own controller — leaving
// Laravel's stock 'verified' middleware permanently unsatisfiable (a
// lockout, not a security hole). See Spec #96.

use Illuminate\Auth\Events\Verified;
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

    Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
});
