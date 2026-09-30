<?php

declare(strict_types=1);

// Spec #142 criterion 1 (and the OAuth half of 3), job #10818.
//
// The callback used to look the user up by the email the provider reported and
// sign in whoever it found. A provider asserting the admin's address signed the
// asserter in as the admin. Now an OAuth login resolves through a stored link
// — (provider, provider's user id) — and email plays no part in it.

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);

    $this->admin = TestUser::factory()->admin()->create(['email' => 'admin@example.com']);
    $this->member = TestUser::factory()->create(['email' => 'member@example.com']);
});

function linkIdentity(TestUser $user, string $provider, string $id): SocialAccount
{
    return SocialAccount::forceCreate(['user_id' => $user->getKey(), 'provider' => $provider, 'provider_user_id' => $id]);
}

it('signs in the linked account — not the one whose email the provider reports', function () {
    linkIdentity($this->member, 'github', 'gh-1001');

    // The attack: a provider identity linked to the member, reporting the
    // admin's address.
    $this->oauth->asserts('github', 'gh-1001', 'admin@example.com');

    $this->get(route('socialite.callback', 'github'))->assertRedirect('/');

    $this->assertAuthenticatedAs($this->member);
});

it('never signs anyone in on an email match alone', function () {
    $this->oauth->asserts('github', 'gh-attacker', 'admin@example.com');

    $this->get(route('socialite.callback', 'github'));

    $this->assertGuest();
});

it('does not create an account for an unlinked identity (yet — CP5 adds rule-bound creation)', function () {
    $this->oauth->asserts('github', 'gh-new', 'stranger@example.com');

    $this->get(route('socialite.callback', 'github'));

    $this->assertGuest();
    expect(TestUser::where('email', 'stranger@example.com')->exists())->toBeFalse();
});

it('sends a linked user with 2FA confirmed to the challenge', function () {
    config()->set('usarrs.two_factor.enabled', true);
    $this->member->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_confirmed_at' => now(),
    ])->save();
    linkIdentity($this->member, 'github', 'gh-1001');
    $this->oauth->asserts('github', 'gh-1001', 'member@example.com');

    $this->get(route('socialite.callback', 'github'))->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($this->member->getKey());
});

it('treats the same id from a different provider as a different identity', function () {
    linkIdentity($this->member, 'github', '42');
    config()->set('usarrs.socialite_providers', ['github', 'gitlab']);
    $this->oauth->asserts('gitlab', '42', 'member@example.com');

    $this->get(route('socialite.callback', 'gitlab'));

    $this->assertGuest();
});

it('404s a provider that is not configured', function () {
    $this->oauth->asserts('gitlab', 'x', 'member@example.com');

    $this->get(route('socialite.callback', 'gitlab'))->assertNotFound();
    $this->get(route('socialite.redirect', 'gitlab'))->assertNotFound();
});

it('sends the redirect through the provider', function () {
    $this->get(route('socialite.redirect', 'github'))
        ->assertRedirect('https://oauth.example/github/authorize');
});

// A unique violation aborts PostgreSQL's whole transaction — including the one
// RefreshDatabase wraps each test in — so the next query fails. A nested
// transaction is a savepoint: only the violating insert rolls back.
function violates(Closure $insert): Closure
{
    return fn () => DB::transaction($insert);
}

describe('the social_accounts table', function () {
    it('holds one identity per (provider, provider id)', function () {
        linkIdentity($this->member, 'github', 'gh-1');

        expect(violates(fn () => linkIdentity($this->admin, 'github', 'gh-1')))
            ->toThrow(QueryException::class);
    });

    it('holds one identity per provider per user', function () {
        linkIdentity($this->member, 'github', 'gh-1');

        expect(violates(fn () => linkIdentity($this->member, 'github', 'gh-2')))
            ->toThrow(QueryException::class);
    });

    it('goes when the user goes', function () {
        linkIdentity($this->member, 'github', 'gh-1');

        $this->member->delete();

        expect(SocialAccount::count())->toBe(0);
    });

    it('is not mass-assignable — only usarrs creates login identities', function () {
        expect(fn () => SocialAccount::create(['user_id' => 1, 'provider' => 'github', 'provider_user_id' => 'x']))
            ->toThrow(MassAssignmentException::class);
    });
});
