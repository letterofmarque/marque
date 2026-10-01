<?php

declare(strict_types=1);

// Spec #142 criterion 4. The callback used to create an account for any
// identity it didn't recognise — under every mode, invites or not — and sign it
// straight in, unverified. Now OAuth creates an account only where the
// registration rules allow one, exactly as /register applies them, and the new
// account is unverified and sent the verification email like anyone else.

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);
});

function newcomer(): ?TestUser
{
    return TestUser::where('email', 'newcomer@example.com')->first();
}

it('creates an account for a new identity when registration is open', function () {
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com', 'New Comer');

    $this->get(route('socialite.callback', 'github'))->assertRedirect('/');

    expect(newcomer())->not->toBeNull()
        ->and(newcomer()->name)->toBe('New Comer');
    $this->assertAuthenticatedAs(newcomer());
    expect(SocialAccount::resolve('github', 'gh-new')?->user_id)->toBe(newcomer()->getKey());
});

it('leaves the new account unverified and sends the verification email', function () {
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');

    $this->get(route('socialite.callback', 'github'));

    expect(newcomer()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo(newcomer(), VerifyEmail::class);
});

it('gives the new account no password anyone could know', function () {
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');

    $this->get(route('socialite.callback', 'github'));

    expect(newcomer()->password)->not->toBeEmpty()
        ->and(Hash::check('', newcomer()->password))->toBeFalse();
});

it('creates nothing when invites are required and none was given', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');

    $this->get(route('socialite.callback', 'github'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(newcomer())->toBeNull();
    $this->assertGuest();
});

it('creates the account and redeems the invite carried through the redirect', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);
    $inviter = TestUser::factory()->create();
    $invite = app(InviteServiceInterface::class)->create($inviter);

    $this->get(route('socialite.redirect', ['provider' => 'github', 'invite' => $invite->code]));
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');
    $this->get(route('socialite.callback', 'github'))->assertRedirect('/');

    expect(newcomer())->not->toBeNull()
        ->and($invite->fresh()->used_by_id)->toBe(newcomer()->getKey());
});

it('refuses an invite that is not valid', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);

    $this->get(route('socialite.redirect', ['provider' => 'github', 'invite' => 'not-a-real-code']));
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');
    $this->get(route('socialite.callback', 'github'))->assertSessionHasErrors('email');

    expect(newcomer())->toBeNull();
});

it('creates nothing for an identity with no email address', function () {
    $this->oauth->asserts('github', 'gh-no-mail', null);

    $this->get(route('socialite.callback', 'github'))->assertSessionHasErrors('email');

    expect(TestUser::count())->toBe(0);
    $this->assertGuest();
});

// Build #124 CP #763 (corrects CP #736).

// Checking an invite and then redeeming it unconditionally let N concurrent
// callbacks carrying one invite each make an account. Redeeming now claims the
// invite only if it is still pending, and an account made on an invite that
// was lost in the race is rolled back with it.
it('makes no account when the invite is redeemed by someone else mid-request', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);
    $inviter = TestUser::factory()->create();
    $invite = app(InviteServiceInterface::class)->create($inviter);
    $winner = TestUser::factory()->create();

    // The concurrent request wins between the check and the redemption.
    TestUser::creating(function (TestUser $user) use ($invite, $winner) {
        if ($user->email === 'newcomer@example.com') {
            DB::table('invites')->where('id', $invite->id)->update(['status' => 'used', 'used_by_id' => $winner->getKey()]);
        }
    });

    $this->get(route('socialite.redirect', ['provider' => 'github', 'invite' => $invite->code]));
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');
    $this->get(route('socialite.callback', 'github'))->assertSessionHasErrors('email');

    // (The winner's write is undone here too — in this test it runs inside the
    // losing request's transaction. A real competitor commits on its own.)
    expect(newcomer())->toBeNull()
        ->and(SocialAccount::count())->toBe(0);
    $this->assertGuest();
});

// The same identity arriving twice at once: the second must not leave an
// orphaned account behind, or fail with a 500.
it('makes no account, and no error page, when the identity is linked mid-request', function () {
    $someone = TestUser::factory()->create();
    TestUser::created(function (TestUser $user) use ($someone) {
        if ($user->email === 'newcomer@example.com') {
            DB::table('usarrs_social_accounts')->insert([
                'user_id' => $someone->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-new',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');

    $this->get(route('socialite.callback', 'github'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(newcomer())->toBeNull();
    $this->assertGuest();
});

it('treats an invite that is not a string as no invite', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);

    $this->get(route('socialite.redirect', 'github').'?invite[]=x');
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');

    $this->get(route('socialite.callback', 'github'))->assertSessionHasErrors('email');

    expect(newcomer())->toBeNull();
});

// With registration closed, "we've sent a link" for a known address and
// "registration is closed" for an unknown one told anybody which addresses
// have accounts here. Both now get the same answer.
it('answers the same whether or not the address has an account, when registration is closed', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);
    TestUser::factory()->create(['email' => 'member@example.com']);

    $this->oauth->asserts('github', 'gh-a', 'member@example.com');
    $known = $this->get(route('socialite.callback', 'github'));
    $knownSession = [session('status'), session('errors')?->getBag('default')->toArray()];
    $this->flushSession();

    $this->oauth->asserts('github', 'gh-b', 'nobody@example.com');
    $unknown = $this->get(route('socialite.callback', 'github'));
    $unknownSession = [session('status'), session('errors')?->getBag('default')->toArray()];

    expect($known->headers->get('Location'))->toBe($unknown->headers->get('Location'))
        ->and($knownSession)->toBe($unknownSession);
});
