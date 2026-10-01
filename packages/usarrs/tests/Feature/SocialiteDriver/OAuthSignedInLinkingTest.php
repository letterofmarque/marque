<?php

declare(strict_types=1);

// Spec #142 criterion 7. A user who is already signed in and completes an OAuth
// round trip is connecting a provider to *their* account — the "link an
// additional provider" the README always promised and the code never did.
//
// What it did instead: treat the callback as a fresh login. An identity linked
// to someone else signed the current user out of their own account and into
// that one; an unlinked identity went down the email-match or account-creation
// paths as if nobody were signed in.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('usarrs.socialite_providers', ['github', 'gitlab']);

    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);

    $this->me = TestUser::factory()->create(['email' => 'me@example.com']);
    $this->someoneElse = TestUser::factory()->create(['email' => 'else@example.com']);
});

function linkFor(TestUser $user, string $provider, string $id): void
{
    SocialAccount::forceCreate(['user_id' => $user->getKey(), 'provider' => $provider, 'provider_user_id' => $id]);
}

it('links an unlinked identity to the signed-in account', function () {
    $this->oauth->asserts('gitlab', 'gl-9', 'me@example.com');

    $this->actingAs($this->me)->get(route('socialite.callback', 'gitlab'));

    $this->assertAuthenticatedAs($this->me);
    expect(SocialAccount::resolve('gitlab', 'gl-9')?->user_id)->toBe($this->me->getKey());
});

it('links it even when the provider reports a different email', function () {
    // The user is already proven — signed in. The provider's email is
    // irrelevant to connecting their own account.
    $this->oauth->asserts('gitlab', 'gl-9', 'personal@elsewhere.example');

    $this->actingAs($this->me)->get(route('socialite.callback', 'gitlab'));

    expect(SocialAccount::resolve('gitlab', 'gl-9')?->user_id)->toBe($this->me->getKey());
    Notification::assertNothingSent();
});

it('never switches to the account an identity is already linked to', function () {
    linkFor($this->someoneElse, 'github', 'gh-theirs');
    $this->oauth->asserts('github', 'gh-theirs', 'else@example.com');

    $this->actingAs($this->me)->get(route('socialite.callback', 'github'))
        ->assertSessionHasErrors('email');

    $this->assertAuthenticatedAs($this->me);
    expect(SocialAccount::resolve('github', 'gh-theirs')->user_id)->toBe($this->someoneElse->getKey());
});

it('never creates a second account for a signed-in user', function () {
    $this->oauth->asserts('gitlab', 'gl-new', 'fresh@example.com');

    $this->actingAs($this->me)->get(route('socialite.callback', 'gitlab'));

    expect(TestUser::where('email', 'fresh@example.com')->exists())->toBeFalse();
    $this->assertAuthenticatedAs($this->me);
});

it('does nothing but confirm when the identity is already theirs', function () {
    linkFor($this->me, 'github', 'gh-mine');
    $this->oauth->asserts('github', 'gh-mine', 'me@example.com');

    $this->actingAs($this->me)->get(route('socialite.callback', 'github'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($this->me);
    expect(SocialAccount::where('user_id', $this->me->getKey())->count())->toBe(1);
});

it('refuses a second identity from a provider the account already has', function () {
    linkFor($this->me, 'github', 'gh-mine');
    $this->oauth->asserts('github', 'gh-another', 'me@example.com');

    $this->actingAs($this->me)->get(route('socialite.callback', 'github'))
        ->assertSessionHasErrors('email');

    $this->assertAuthenticatedAs($this->me);
    expect(SocialAccount::resolve('github', 'gh-another'))->toBeNull();
});

// Build #124 CP #763: the same identity connected concurrently — the loser is
// told it is connected elsewhere, not shown a 500.
it('reports a connection made concurrently instead of failing', function () {
    SocialAccount::creating(function () {
        DB::table('usarrs_social_accounts')->insert([
            'user_id' => $this->someoneElse->getKey(), 'provider' => 'gitlab', 'provider_user_id' => 'gl-9',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    $this->oauth->asserts('gitlab', 'gl-9', 'me@example.com');

    $this->actingAs($this->me)->get(route('socialite.callback', 'gitlab'))
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasErrors('email');

    $this->assertAuthenticatedAs($this->me);
});
