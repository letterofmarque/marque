<?php

declare(strict_types=1);

// Found by the usarrs 10.0.0 docs read-through: the magic link was built with
// url('/auth/magic-link/verify?...'), ignoring usarrs.prefix, while the route
// is registered under it. On a prefixed install every emailed link 404'd.

use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Notifications\MagicLinkNotification;
use Marque\Usarrs\Tests\TestUser;

it('emails a magic link under the prefix, and it signs in', function () {
    Notification::fake();
    config()->set('usarrs.auth_driver', 'magic_link');
    $user = TestUser::factory()->create();

    Livewire::test(Login::class)->set('email', $user->email)->call('login');

    $url = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function ($n) use (&$url) {
        $url = $n->url;

        return true;
    });

    expect(parse_url($url, PHP_URL_PATH))->toBe('/members/auth/magic-link/verify');

    $this->get($url)->assertRedirect();
    $this->assertAuthenticatedAs($user);
});
