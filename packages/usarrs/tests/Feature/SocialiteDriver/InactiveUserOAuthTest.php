<?php

declare(strict_types=1);

// Issue #10857, the OAuth path: a banned user whose provider identity is linked
// is refused at the callback like every other way in.

use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;

it('refuses a banned user signing in through a linked provider identity', function () {
    $oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $oauth);

    $user = TestUser::factory()->create(['email' => 'banned@example.com', 'status' => 'banned']);
    SocialAccount::forceCreate(['user_id' => $user->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-9']);
    $oauth->asserts('github', 'gh-9', 'banned@example.com');

    $this->get(route('socialite.callback', 'github'))->assertRedirect(route('login'));

    $this->assertGuest();
});
