<?php

declare(strict_types=1);

// Job #355 step 4: the low-severity findings from the 8.1.0 pre-tag reviews
// (#10880) that touch the OAuth paths.

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Marque\Usarrs\Contracts\OAuthProvider;
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

// Confirmation mail

it('mails an account at most three connection links an hour, answering the same either way', function () {
    $member = TestUser::factory()->create(['email' => 'member@example.com']);

    $answers = [];
    foreach (range(1, 5) as $i) {
        $this->oauth->asserts('github', "gh-{$i}", 'member@example.com');
        session()->flush();
        $response = $this->get(route('socialite.callback', 'github'));
        $answers[] = $response->headers->get('Location').'|'.session('status').'|'.session('errors')?->first('email');
    }

    Notification::assertSentToTimes($member, OAuthLinkConfirmation::class, 3);
    // The fourth and fifth visitors are told what the first was: a throttle
    // that answered differently would say the address has an account.
    expect(array_unique($answers))->toHaveCount(1);
});

it('queues the connection mail, so a known address answers as fast as an unknown one', function () {
    // A synchronous send made a matching address measurably slower to answer
    // under closed registration, where the responses are otherwise identical.
    expect(new OAuthLinkConfirmation('github', 'https://x', 'label'))->toBeInstanceOf(ShouldQueue::class);
});

// Provider data

it('refuses an identity with an empty provider id', function () {
    $this->oauth->asserts('github', '', 'new@example.com');

    $this->get(route('socialite.callback', 'github'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

    expect(TestUser::where('email', 'new@example.com')->exists())->toBeFalse()
        ->and(SocialAccount::count())->toBe(0);
});

it('truncates a provider name longer than the column, instead of failing', function () {
    $this->oauth->asserts('github', 'gh-long', 'long@example.com', name: Str::repeat('n', 400));

    $this->get(route('socialite.callback', 'github'))->assertRedirect();

    expect(mb_strlen(TestUser::where('email', 'long@example.com')->value('name')))->toBe(255);
});

it('treats an email longer than the column as no email', function () {
    $this->oauth->asserts('github', 'gh-x', Str::repeat('a', 250).'@example.com');

    $this->get(route('socialite.callback', 'github'))->assertSessionHasErrors('email');

    expect(TestUser::count())->toBe(0);
});

// The race message

it('says an account was made moments ago, not that the provider was connected, when the email is what raced', function () {
    // A second sign-up for the same address won the race: users.email is the
    // unique violation, not the identity.
    TestUser::creating(function (TestUser $user) {
        if ($user->email === 'racer@example.com') {
            DB::table('users')->insert(['name' => 'Winner', 'email' => 'racer@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    $this->oauth->asserts('github', 'gh-racer', 'racer@example.com');

    $response = $this->get(route('socialite.callback', 'github'))->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))
        ->toContain(__('An account with that email address was made moments ago'))
        ->not->toContain('connected moments ago');
});

// The connect page

it('warns on the connect page that an unproven account will lose what it gained', function () {
    $account = TestUser::factory()->unverified()->create(['email' => 'victim@example.com']);
    SocialAccount::forceCreate(['user_id' => $account->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-squat']);
    config()->set('usarrs.socialite_providers', ['github', 'gitlab']);

    $this->oauth->asserts('gitlab', 'gl-owner', 'victim@example.com');
    $this->get(route('socialite.callback', 'gitlab'));

    $url = null;
    Notification::assertSentTo($account, OAuthLinkConfirmation::class, function ($n) use (&$url) {
        $url = $n->url;

        return true;
    });

    $this->get($url)->assertOk()->assertSee(__('Connecting also removes everything else that can sign in to this account'));
});

it('gives no such warning to an account whose address is proven', function () {
    $account = TestUser::factory()->create(['email' => 'member@example.com']);

    $this->oauth->asserts('github', 'gh-1', 'member@example.com');
    $this->get(route('socialite.callback', 'github'));

    $url = null;
    Notification::assertSentTo($account, OAuthLinkConfirmation::class, function ($n) use (&$url) {
        $url = $n->url;

        return true;
    });

    $this->get($url)->assertOk()->assertDontSee(__('Connecting also removes everything else that can sign in to this account'));
});
