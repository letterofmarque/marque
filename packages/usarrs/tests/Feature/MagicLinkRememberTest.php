<?php

declare(strict_types=1);

// Job #355 step 3 (#10858 item 3). Every magic-link sign-in set a long-lived
// remember cookie. The login form's checkbox now applies under magic_link too,
// and the choice rides the emailed link.

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Notifications\MagicLinkNotification;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('usarrs.auth_driver', 'magic_link');
});

function rememberingMagicLink(TestUser $user, bool $remember): string
{
    Livewire::test(Login::class)->set('email', $user->email)->set('remember', $remember)->call('login');

    $url = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function ($n) use (&$url) {
        $url = $n->url;

        return true;
    });

    return $url;
}

it('shows the remember-me box under magic_link', function () {
    $this->get(route('login'))->assertOk()->assertSee(__('Remember me'));
});

it('remembers a magic-link sign-in only when asked', function (bool $ticked) {
    $user = TestUser::factory()->create();

    $response = $this->get(rememberingMagicLink($user, $ticked));

    $this->assertAuthenticatedAs($user);
    $ticked
        ? $response->assertCookie(Auth::guard()->getRecallerName())
        : $response->assertCookieMissing(Auth::guard()->getRecallerName());
})->with(['ticked' => true, 'not ticked' => false]);

// Job #355, from its independent check: a used magic link proves the inbox, the
// same proof a verification link gives. Without this a magic-link member who
// never clicked the separate verification mail had no way to confirm it's them.
it('marks the address verified when a magic link signs in', function () {
    $user = TestUser::factory()->unverified()->create();

    $this->get(rememberingMagicLink($user, false));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
