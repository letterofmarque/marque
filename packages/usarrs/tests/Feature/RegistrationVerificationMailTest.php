<?php

declare(strict_types=1);

// Issue #10840. Password registration created the account and signed it in,
// but neither fired Registered nor sent the verification email, so a new user
// met `verified` middleware with no mail until they found "resend". Every way
// an account is made now announces it through one place, which sends exactly
// one mail whether or not the app wires Laravel's own listener.

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    Event::forget(Registered::class);
});

function registerNewcomer(): TestUser
{
    Livewire::test(Register::class)
        ->set('name', 'New Comer')
        ->set('email', 'newcomer@example.com')
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->call('register');

    return TestUser::where('email', 'newcomer@example.com')->firstOrFail();
}

it('sends the verification email when the app has no listener for Registered', function () {
    $user = registerNewcomer();

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('sends it once, not twice, when the app wires Laravel\'s own listener', function () {
    // What a stock Laravel 11+ app does for itself.
    Event::listen(Registered::class, SendEmailVerificationNotification::class);

    $user = registerNewcomer();

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('fires Registered, so the app can hook new accounts', function () {
    $fired = [];
    Event::listen(Registered::class, function (Registered $event) use (&$fired) {
        $fired[] = $event->user->email;
    });

    registerNewcomer();

    expect($fired)->toBe(['newcomer@example.com']);
});
