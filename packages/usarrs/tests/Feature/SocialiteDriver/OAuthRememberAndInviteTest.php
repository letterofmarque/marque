<?php

declare(strict_types=1);

// Job #355 step 3 (#10858 items 3 and 4).
//
// Remember-me: every OAuth sign-in set a long-lived remember cookie, whatever
// the member wanted. It is now a checkbox that rides the round trip, off unless
// ticked, as on the password form.
//
// Invite planting: /auth/{provider}/redirect?invite=CODE was a GET, so any page
// could plant an attacker's invite in a visitor's session, and an auto-approving
// provider then made the visitor's account on it. An invite now reaches the
// callback only in a POST from the page that shows it (/register under
// socialite, which used to 404, so the invite email's own link was dead).

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Livewire\Auth\ConfirmOAuthLink;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Notifications\OAuthLinkConfirmation;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('usarrs.socialite_providers', ['github']);

    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);
});

function recaller(): string
{
    return Auth::guard()->getRecallerName();
}

function inviteFor(): object
{
    config()->set(['usarrs.invites.enabled' => true, 'usarrs.invites.required' => true]);

    return app(InviteServiceInterface::class)->create(TestUser::factory()->create());
}

// Remember-me

it('does not remember an OAuth sign-in nobody asked to be remembered', function (string $method) {
    $member = TestUser::factory()->create();
    SocialAccount::forceCreate(['user_id' => $member->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-1']);

    $this->call($method, route('socialite.redirect', 'github'));
    $this->oauth->asserts('github', 'gh-1', $member->email);

    $this->get(route('socialite.callback', 'github'))->assertCookieMissing(recaller());
    $this->assertAuthenticatedAs($member);
})->with(['GET', 'POST']);

it('remembers an OAuth sign-in when the member ticked the box', function () {
    $member = TestUser::factory()->create();
    SocialAccount::forceCreate(['user_id' => $member->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-1']);

    $this->post(route('socialite.redirect', 'github'), ['remember' => '1']);
    $this->oauth->asserts('github', 'gh-1', $member->email);

    $this->get(route('socialite.callback', 'github'))->assertCookie(recaller());
});

it('remembers an OAuth sign-up only when asked', function (bool $ticked) {
    $this->post(route('socialite.redirect', 'github'), $ticked ? ['remember' => '1'] : []);
    $this->oauth->asserts('github', 'gh-new', 'new@example.com');

    $response = $this->get(route('socialite.callback', 'github'));

    $ticked ? $response->assertCookie(recaller()) : $response->assertCookieMissing(recaller());
})->with(['ticked' => true, 'not ticked' => false]);

it('asks the inbox owner on the connection page, whatever the round trip chose', function (bool $startedRemembered, bool $ownerTicks) {
    // From the independent check: the choice belonged to whoever started the
    // round trip, who may not be the inbox owner confirming. The page asks.
    $member = TestUser::factory()->create(['email' => 'member@example.com', 'remember_token' => null]);

    $this->post(route('socialite.redirect', 'github'), $startedRemembered ? ['remember' => '1'] : []);
    $this->oauth->asserts('github', 'gh-1', 'member@example.com');
    $this->get(route('socialite.callback', 'github'));

    $token = null;
    Notification::assertSentTo($member, OAuthLinkConfirmation::class, function ($n) use (&$token) {
        $token = basename(parse_url($n->url, PHP_URL_PATH));

        return true;
    });

    Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => $token])
        ->assertSee(__('Remember me'))
        ->set('remember', $ownerTicks)
        ->call('connect');

    expect(filled($member->fresh()->getRememberToken()))->toBe($ownerTicks);
})->with([
    'started remembered, owner declines' => [true, false],
    'started not remembered, owner ticks' => [false, true],
]);

// Invites

it('ignores an invite in a GET to the redirect', function () {
    $invite = inviteFor();

    $this->get(route('socialite.redirect', ['provider' => 'github', 'invite' => $invite->code]));

    expect(session('usarrs.oauth.invite'))->toBeNull();

    $this->oauth->asserts('github', 'gh-new', 'new@example.com');
    $this->get(route('socialite.callback', 'github'));

    expect(TestUser::where('email', 'new@example.com')->exists())->toBeFalse()
        ->and($invite->fresh()->used_by_id)->toBeNull();
});

it('takes an invite POSTed from the page that shows it', function () {
    $invite = inviteFor();

    $this->post(route('socialite.redirect', 'github'), ['invite' => $invite->code])
        ->assertRedirect('https://oauth.example/github/authorize');
    $this->oauth->asserts('github', 'gh-new', 'new@example.com');
    $this->get(route('socialite.callback', 'github'));

    $newcomer = TestUser::where('email', 'new@example.com')->first();
    expect($newcomer)->not->toBeNull()
        ->and($invite->fresh()->used_by_id)->toBe($newcomer->getKey());
});

it('opens /register under socialite for the invite email\'s link, with the invite in a POST form', function () {
    $invite = inviteFor();

    $this->get(route('register', ['invite' => $invite->code]))
        ->assertOk()
        ->assertSee('method="POST"', escape: false)
        ->assertSee(route('socialite.redirect', 'github'))
        ->assertSee('value="'.$invite->code.'"', escape: false)
        ->assertDontSee('type="password"', escape: false);
});

it('still makes no account from the /register form under socialite', function () {
    Livewire::test(Register::class)
        ->set('name', 'X')->set('email', 'x@example.com')
        ->set('password', 'password123')->set('password_confirmation', 'password123')
        ->call('register')
        ->assertStatus(404);
});

it('shows a remember-me box with the provider buttons on the login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('name="remember"', escape: false)
        ->assertSee('method="POST"', escape: false);
});
