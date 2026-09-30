<?php

declare(strict_types=1);

// Spec #142 criterion 2. An OAuth identity nobody has linked, carrying an email
// that belongs to an account here, is exactly the takeover — and also every
// existing OAuth user on the day this ships, since no links exist yet.
//
// So a match signs no one in. It sends a signed link to the address the
// account already has. Whoever controls that inbox is the account holder;
// following the link creates the link and finishes the login through the seam,
// two-factor included. An attacker asserting the email doesn't hold the inbox.

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Fortify;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Notifications\OAuthLinkConfirmation;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Notification::fake();

    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);

    $this->member = TestUser::factory()->create(['email' => 'member@example.com']);
});

function confirmationUrlFor(TestUser $user, string $provider = 'github', string $id = 'gh-77', int $minutes = 60): string
{
    return URL::temporarySignedRoute('socialite.link.confirm', now()->addMinutes($minutes), [
        'provider' => $provider,
        'user' => $user->getKey(),
        'provider_user_id' => $id,
    ]);
}

describe('an unlinked identity whose email matches an account', function () {
    it('signs no one in', function () {
        $this->oauth->asserts('github', 'gh-77', 'member@example.com');

        $this->get(route('socialite.callback', 'github'))->assertRedirect(route('login'));

        $this->assertGuest();
        expect(SocialAccount::count())->toBe(0);
    });

    it('sends the confirmation to the account holder — the address this site already has', function () {
        $this->oauth->asserts('github', 'gh-77', 'member@example.com');

        $this->get(route('socialite.callback', 'github'));

        Notification::assertSentTo($this->member, OAuthLinkConfirmation::class,
            fn (OAuthLinkConfirmation $n) => $n->provider === 'github'
                && str_contains($n->url, 'provider_user_id=gh-77')
                && str_contains($n->url, 'signature='));
        Notification::assertCount(1);
    });

    it('tells the visitor to check their email without saying whose', function () {
        $this->oauth->asserts('github', 'gh-77', 'member@example.com');

        $this->followingRedirects()
            ->get(route('socialite.callback', 'github'))
            ->assertSee('check your email')
            ->assertDontSee('member@example.com');
    });
});

describe('following the confirmation link', function () {
    it('links the identity and signs the account holder in', function () {
        $this->get(confirmationUrlFor($this->member))->assertRedirect('/');

        $this->assertAuthenticatedAs($this->member);
        expect(SocialAccount::resolve('github', 'gh-77')?->user_id)->toBe($this->member->getKey());
    });

    it('means the next OAuth login goes straight in', function () {
        $this->get(confirmationUrlFor($this->member));
        auth()->logout();
        Notification::fake();

        $this->oauth->asserts('github', 'gh-77', 'member@example.com');
        $this->get(route('socialite.callback', 'github'))->assertRedirect('/');

        $this->assertAuthenticatedAs($this->member);
        Notification::assertNothingSent();
    });

    it('still puts a 2FA user through the challenge', function () {
        config()->set('usarrs.two_factor.enabled', true);
        $this->member->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->get(confirmationUrlFor($this->member))->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    });

    it('does nothing once it has expired', function () {
        $url = confirmationUrlFor($this->member, minutes: 60);
        $this->travel(61)->minutes();

        $this->get($url)->assertForbidden();

        $this->assertGuest();
        expect(SocialAccount::count())->toBe(0);
    });

    it('does nothing if anything in it was changed', function () {
        $url = str_replace('provider_user_id=gh-77', 'provider_user_id=gh-attacker', confirmationUrlFor($this->member));

        $this->get($url)->assertForbidden();

        $this->assertGuest();
        expect(SocialAccount::count())->toBe(0);
    });

    it('refuses an identity that was linked to someone else in the meantime', function () {
        $other = TestUser::factory()->create();
        SocialAccount::forceCreate(['user_id' => $other->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-77']);

        $this->get(confirmationUrlFor($this->member))->assertRedirect(route('login'));

        $this->assertGuest();
        expect(SocialAccount::resolve('github', 'gh-77')->user_id)->toBe($other->getKey());
    });

    it('refuses a second identity from the same provider for the same account', function () {
        SocialAccount::forceCreate(['user_id' => $this->member->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-original']);

        $this->get(confirmationUrlFor($this->member, id: 'gh-77'))->assertRedirect(route('login'));

        $this->assertGuest();
        expect(SocialAccount::resolve('github', 'gh-77'))->toBeNull();
    });
});
