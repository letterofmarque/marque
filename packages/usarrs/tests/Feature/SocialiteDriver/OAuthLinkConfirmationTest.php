<?php

declare(strict_types=1);

// Spec #142 criterion 2, as corrected by Build #124 CP #762.
//
// An OAuth identity nobody has linked, carrying an email that belongs to an
// account here, is exactly the takeover — and also every existing OAuth user on
// upgrade day. So it signs no one in; the account's own address is emailed a
// link. Following that link proves the inbox.
//
// The first version connected on the GET itself and named nothing. A mail
// scanner following links (Safe Links, Mimecast), or one unwary click, connected
// an attacker's identity. Now the link opens a page that names the provider
// account, connecting takes a deliberate action on it, and the link works once.
//
// Every test here follows the URL the controller actually emailed — not one
// this file builds — so a controller that signed the wrong thing fails.

use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Livewire\Auth\ConfirmOAuthLink;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Notifications\OAuthLinkConfirmation;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Notification::fake();

    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);

    $this->member = TestUser::factory()->create(['name' => 'Member', 'email' => 'member@example.com']);
});

/**
 * Complete an OAuth trip for an unlinked identity carrying the member's email,
 * and return the confirmation URL the controller emailed.
 */
function emailedConfirmation(object $test, string $id = 'gh-77', string $name = 'Octo Cat'): string
{
    $test->oauth->asserts('github', $id, 'member@example.com', $name);
    $test->get(route('socialite.callback', 'github'));

    $url = null;
    Notification::assertSentTo($test->member, OAuthLinkConfirmation::class, function (OAuthLinkConfirmation $n) use (&$url) {
        $url = $n->url;

        return true;
    });

    return $url;
}

/** The token segment of an emailed confirmation URL. */
function tokenOf(string $url): string
{
    return basename(parse_url($url, PHP_URL_PATH));
}

describe('an unlinked identity whose email matches an account', function () {
    it('signs no one in and connects nothing', function () {
        emailedConfirmation($this);

        $this->assertGuest();
        expect(SocialAccount::count())->toBe(0);
    });

    it('emails the account holder a signed link', function () {
        $url = emailedConfirmation($this);

        expect($url)->toContain('/auth/github/link/')
            ->and($url)->toContain('signature=');
        Notification::assertCount(1);
    });

    it('names the provider account in the email', function () {
        emailedConfirmation($this, name: 'Octo Cat');

        Notification::assertSentTo($this->member, OAuthLinkConfirmation::class,
            fn (OAuthLinkConfirmation $n) => str_contains(implode(' ', $n->toMail($this->member)->introLines), 'Octo Cat'));
    });

    it('tells the visitor to check their email without saying whose', function () {
        $this->oauth->asserts('github', 'gh-77', 'member@example.com');

        $this->followingRedirects()
            ->get(route('socialite.callback', 'github'))
            ->assertSee('check your email')
            ->assertDontSee('member@example.com');
    });

    it('sends nothing when the account already has that provider connected', function () {
        SocialAccount::forceCreate(['user_id' => $this->member->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-original']);
        $this->oauth->asserts('github', 'gh-77', 'member@example.com');

        $this->get(route('socialite.callback', 'github'));

        Notification::assertNothingSent();
        $this->assertGuest();
    });
});

describe('opening the emailed link', function () {
    it('shows a page naming the provider account, and connects nothing', function () {
        $url = emailedConfirmation($this, name: 'Octo Cat');

        $this->get($url)->assertOk()->assertSee('Octo Cat')->assertSee('Github');

        $this->assertGuest();
        expect(SocialAccount::count())->toBe(0);
    });

    it('connects nothing however many times it is opened — a scanner following links changes nothing', function () {
        $url = emailedConfirmation($this);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        expect(SocialAccount::count())->toBe(0);
        $this->assertGuest();
    });

    it('is refused once expired', function () {
        $url = emailedConfirmation($this);
        $this->travel(61)->minutes();

        $this->get($url)->assertForbidden();
    });

    it('is refused if anything in it was changed', function () {
        $url = emailedConfirmation($this);

        $this->get(str_replace(tokenOf($url), str_repeat('a', 40), $url))->assertForbidden();
    });
});

describe('confirming on the page', function () {
    it('connects the identity and signs the account holder in', function () {
        $url = emailedConfirmation($this);

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => tokenOf($url)])
            ->call('connect')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($this->member);
        expect(SocialAccount::resolve('github', 'gh-77')?->user_id)->toBe($this->member->getKey());
    });

    it('works exactly once', function () {
        $token = tokenOf(emailedConfirmation($this));

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => $token])->call('connect');
        auth()->logout();

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => $token])
            ->call('connect')
            ->assertHasErrors('token');

        $this->assertGuest();
    });

    it('still puts a 2FA user through the challenge', function () {
        config()->set('usarrs.two_factor.enabled', true);
        $this->member->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $token = tokenOf(emailedConfirmation($this));

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => $token])
            ->call('connect')
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    });

    it('refuses an identity linked to someone else in the meantime', function () {
        $token = tokenOf(emailedConfirmation($this));
        $other = TestUser::factory()->create();
        SocialAccount::forceCreate(['user_id' => $other->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-77']);

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => $token])
            ->call('connect')
            ->assertHasErrors('token');

        $this->assertGuest();
        expect(SocialAccount::resolve('github', 'gh-77')->user_id)->toBe($other->getKey());
    });

    it('refuses a token that never existed', function () {
        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => str_repeat('z', 40)])
            ->call('connect')
            ->assertHasErrors('token');

        expect(SocialAccount::count())->toBe(0);
    });
});
