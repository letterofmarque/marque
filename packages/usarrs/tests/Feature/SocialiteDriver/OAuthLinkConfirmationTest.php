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

use Illuminate\Support\Facades\DB;
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

// Build #124 CP #763 (corrects CP #736). Confirming the emailed link is the
// first thing anyone has done that proves the inbox. An account created by
// OAuth was never proven: its creator only had a provider that *reported* the
// address. So an attacker could create the account first, under the victim's
// address, and keep their own connection after the victim confirmed theirs
// into it. Confirming now verifies the address and drops what the account
// gained before anyone proved it.
describe('confirming proves the inbox', function () {
    beforeEach(function () {
        config()->set('usarrs.socialite_providers', ['github', 'gitlab']);
    });

    /** The attacker's account: made by OAuth under an address they don't own. */
    function squat(object $test, string $provider, string $id): TestUser
    {
        $test->oauth->asserts($provider, $id, 'victim@example.com', 'Not The Victim');
        $test->get(route('socialite.callback', $provider));
        auth()->logout();

        return TestUser::where('email', 'victim@example.com')->sole();
    }

    /** The victim's own OAuth trip, and the token the controller mailed them. */
    function victimConfirms(object $test, TestUser $account, string $provider, string $id, bool $thenSignOut = true): void
    {
        $test->oauth->asserts($provider, $id, 'victim@example.com', 'The Victim');
        $test->get(route('socialite.callback', $provider));

        $url = null;
        Notification::assertSentTo($account, OAuthLinkConfirmation::class, function (OAuthLinkConfirmation $n) use (&$url) {
            $url = $n->url;

            return true;
        });

        Livewire::test(ConfirmOAuthLink::class, ['provider' => $provider, 'token' => tokenOf($url)])->call('connect');

        if ($thenSignOut) {
            auth()->logout();
        }
    }

    it('marks the address verified', function () {
        $this->member->forceFill(['email_verified_at' => null])->save();

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => tokenOf(emailedConfirmation($this))])
            ->call('connect');

        expect($this->member->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('drops a connection the account gained before its address was proven', function () {
        $account = squat($this, 'gitlab', 'gl-attacker');

        victimConfirms($this, $account, 'github', 'gh-victim');

        expect(SocialAccount::resolve('gitlab', 'gl-attacker'))->toBeNull()
            ->and(SocialAccount::resolve('github', 'gh-victim')?->user_id)->toBe($account->getKey());

        // And the attacker's identity no longer signs anyone in.
        $this->oauth->asserts('gitlab', 'gl-attacker', 'victim@example.com');
        $this->get(route('socialite.callback', 'gitlab'));
        $this->assertGuest();
    });

    it('lets the owner in even when the squatter took the same provider', function () {
        $account = squat($this, 'github', 'gh-attacker');

        victimConfirms($this, $account, 'github', 'gh-victim');

        expect(SocialAccount::resolve('github', 'gh-attacker'))->toBeNull()
            ->and(SocialAccount::resolve('github', 'gh-victim')?->user_id)->toBe($account->getKey());
    });

    it('ends any remembered sign-in from before the address was proven', function () {
        // The attacker signs in remembered — every OAuth login is — and keeps
        // that cookie. (squat() signs out afterwards, and signing out cycles the
        // token, so take it from a fresh remembered login.)
        $account = squat($this, 'gitlab', 'gl-attacker');
        $this->oauth->asserts('gitlab', 'gl-attacker', 'victim@example.com');
        $this->get(route('socialite.callback', 'gitlab'));
        $before = $account->fresh()->getRememberToken();
        expect($before)->not->toBeEmpty();
        // Leave without logout(), which would cycle the token and hide the bug.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        victimConfirms($this, $account, 'github', 'gh-victim', thenSignOut: false);

        expect($account->fresh()->getRememberToken())->not->toBe($before);
    });

    // Profile security settings sit behind `auth`, not `verified`, so the
    // squatter could add a passkey (a way back in) or two-factor (the owner
    // locked out by the squatter's codes) as well as an OAuth connection.
    it('drops a passkey the account gained before its address was proven', function () {
        $account = squat($this, 'gitlab', 'gl-attacker');
        $account->passkeys()->create(['name' => 'Squatter', 'credential_id' => 'cred-squat', 'credential' => ['type' => 'public-key']]);

        victimConfirms($this, $account, 'github', 'gh-victim');

        expect($account->passkeys()->count())->toBe(0);
    });

    it('turns off two-factor the squatter set up', function () {
        $account = squat($this, 'gitlab', 'gl-attacker');
        $account->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ])->save();

        victimConfirms($this, $account, 'github', 'gh-victim');

        $account->refresh();
        expect($account->two_factor_secret)->toBeNull()
            ->and($account->two_factor_confirmed_at)->toBeNull();
    });

    // Build #124 CP #776. Sessions expire only when idle, so a squatter who
    // keeps theirs alive kept the account after the owner proved the inbox —
    // and could connect a fresh identity from it, for good.
    it("ends the squatter's open session", function () {
        $this->oauth->asserts('gitlab', 'gl-attacker', 'victim@example.com', 'Not The Victim');
        $this->get(route('socialite.callback', 'gitlab'));
        $account = TestUser::where('email', 'victim@example.com')->sole();
        $squatterSession = session()->all();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        victimConfirms($this, $account, 'github', 'gh-victim');

        // The squatter comes back with the session they kept.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession($squatterSession);

        $this->get(route('profile.show'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it("refuses a connection from the squatter's open session", function () {
        $this->oauth->asserts('gitlab', 'gl-attacker', 'victim@example.com', 'Not The Victim');
        $this->get(route('socialite.callback', 'gitlab'));
        $account = TestUser::where('email', 'victim@example.com')->sole();
        $squatterSession = session()->all();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        victimConfirms($this, $account, 'github', 'gh-victim');

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession($squatterSession);
        $this->oauth->asserts('gitlab', 'gl-attacker-2', 'elsewhere@example.com');
        $this->get(route('socialite.callback', 'gitlab'));

        expect(SocialAccount::resolve('gitlab', 'gl-attacker-2')?->user_id)->not->toBe($account->getKey());
    });

    // Accounts made by OAuth before 8.1 were never verified, and had no stored
    // connection (the table is new). Their owners confirming their own address
    // on upgrade day must not cost them the 2FA and passkeys they set up. Only
    // an account that already holds an OAuth connection — every account OAuth
    // has made since 8.1 — can be a squatter's.
    it("verifies a pre-8.1 account but keeps its owner's passkeys and two-factor", function () {
        $this->member->forceFill(['email_verified_at' => null])->save();
        $this->member->passkeys()->create(['name' => 'Mine', 'credential_id' => 'cred-legacy', 'credential' => ['type' => 'public-key']]);
        $this->member->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ])->save();

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => tokenOf(emailedConfirmation($this))])
            ->call('connect');

        $member = $this->member->fresh();
        expect($member->hasVerifiedEmail())->toBeTrue()
            ->and($member->passkeys()->count())->toBe(1)
            ->and($member->two_factor_confirmed_at)->not->toBeNull()
            ->and(SocialAccount::resolve('github', 'gh-77')?->user_id)->toBe($member->getKey());
    });

    it('keeps the connections of an account whose address was already proven', function () {
        SocialAccount::forceCreate(['user_id' => $this->member->getKey(), 'provider' => 'gitlab', 'provider_user_id' => 'gl-mine']);

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => tokenOf(emailedConfirmation($this))])
            ->call('connect');

        expect(SocialAccount::resolve('gitlab', 'gl-mine')?->user_id)->toBe($this->member->getKey());
    });

    it('keeps the passkeys and two-factor of an account whose address was already proven', function () {
        $this->member->passkeys()->create(['name' => 'Mine', 'credential_id' => 'cred-mine', 'credential' => ['type' => 'public-key']]);
        $this->member->forceFill(['two_factor_secret' => 'kept', 'two_factor_confirmed_at' => null])->save();

        Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => tokenOf(emailedConfirmation($this))])
            ->call('connect');

        expect($this->member->passkeys()->count())->toBe(1)
            ->and($this->member->fresh()->two_factor_secret)->toBe('kept');
    });
});

// PostgreSQL and SQLite compare strings case-sensitively. A provider reporting
// `Member@Example.com` for the account `member@example.com` used to miss it —
// a second account for the same inbox, or, with registration closed, a lockout.
it('matches the account whatever case the provider reports its address in', function () {
    $this->oauth->asserts('github', 'gh-77', 'Member@Example.COM');

    $this->get(route('socialite.callback', 'github'));

    Notification::assertSentTo($this->member, OAuthLinkConfirmation::class);
    expect(TestUser::count())->toBe(1);
});

// Two clicks, two tabs, or a retry can connect the same pending link at once.
// The loser must hear "already connected", not see a 500.
it('reports a connection made concurrently instead of failing', function () {
    $token = tokenOf(emailedConfirmation($this));
    $other = TestUser::factory()->create();
    SocialAccount::creating(function () use ($other) {
        DB::table('usarrs_social_accounts')->insert([
            'user_id' => $other->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-77',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    Livewire::test(ConfirmOAuthLink::class, ['provider' => 'github', 'token' => $token])
        ->call('connect')
        ->assertHasErrors('token');

    $this->assertGuest();
});

// Build #124 CP #776. The label named the provider account by display name and
// email — but the email is, by construction, the recipient's own, and the
// display name is whatever the asserting party chose. It said nothing an
// attacker couldn't copy. The provider's own handle and id are what tell one
// account from another.
it('names the provider account by its handle and id, not by the address it shares', function () {
    $this->oauth->asserts('github', 'gh-583231', 'member@example.com', 'Octo Cat', 'octocat');
    $this->get(route('socialite.callback', 'github'));

    Notification::assertSentTo($this->member, OAuthLinkConfirmation::class, function (OAuthLinkConfirmation $n) {
        $intro = implode(' ', $n->toMail($this->member)->introLines);

        return str_contains($intro, '@octocat')
            && str_contains($intro, 'gh-583231')
            && ! str_contains($intro, 'member@example.com');
    });
});

// The display name went into a markdown mail as-is: a provider name of
// "[Reset your password](https://evil.example)" rendered as a live link in
// this site's own mail.
it('renders a provider display name as text, never as markup', function () {
    $this->oauth->asserts('github', 'gh-77', 'member@example.com', '[Reset your password now](https://evil.example/phish) <b>x</b>', 'see https://evil.example/bare');
    $this->get(route('socialite.callback', 'github'));

    Notification::assertSentTo($this->member, OAuthLinkConfirmation::class, function (OAuthLinkConfirmation $n) {
        $html = (string) $n->toMail($this->member)->render();

        return ! str_contains($html, 'href="https://evil.example')
            && ! str_contains($html, '<b>x</b>');
    });
});
