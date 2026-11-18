<?php

declare(strict_types=1);

// #11154. Nothing let a member end their other signed-in browsers: Laravel's
// logoutOtherDevices() runs only on a password change, out of reach for magic
// link and OAuth members. A thief's session lived until it idled out. Now each
// session is stamped with the member's session epoch at sign-in, "sign out
// every other browser" moves the epoch on, and a session whose stamp doesn't
// match ends on its next request. The password is never touched.

use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Marque\Usarrs\Auth\SessionEpoch;
use Marque\Usarrs\Events\OtherSessionsEnded;
use Marque\Usarrs\Livewire\Profile\OtherSessions;
use Marque\Usarrs\Notifications\OAuthConnectionNotice;
use Marque\Usarrs\Notifications\StepUpConfirmationLink;
use Marque\Usarrs\Tests\TestUser;

function memberSignedIn(mixed $test): TestUser
{
    $member = TestUser::factory()->create(['password' => Hash::make('the-password')]);
    $test->actingAs($member)->withSession([SessionEpoch::SESSION_KEY => 0, 'auth.password_confirmed_at' => time()]);

    return $member;
}

function endOthersAs(TestUser $member): mixed
{
    return Livewire::actingAs($member)->test(OtherSessions::class)->call('endOthers');
}

/** A request from another browser of $member's, holding the stamp it signed in with. */
function otherBrowser(mixed $test, TestUser $member, int $stamp): mixed
{
    $test->flushSession();

    return $test->actingAs($member)->withSession([SessionEpoch::SESSION_KEY => $stamp])->get(route('profile.show'));
}

describe('signing out every other browser', function () {
    it('ends another browser on its next request, keeps this one, and leaves the password alone', function (string $driver) {
        config()->set('usarrs.auth_driver', $driver);
        $member = memberSignedIn($this);

        endOthersAs($member)->assertHasNoErrors()->assertSet('done', true);

        // This browser: restamped with the new epoch, still signed in.
        expect(session(SessionEpoch::SESSION_KEY))->toBe(1)
            ->and($member->fresh()->session_epoch)->toBe(1);
        $this->get(route('profile.show'))->assertOk();

        // Another browser, still holding epoch 0.
        otherBrowser($this, $member, 0)->assertRedirect(route('login'));
        $this->assertGuest();

        expect(Hash::check('the-password', $member->fresh()->password))->toBeTrue();
    })->with(['password', 'magic_link']);

    it('refuses an action from a tab another browser opened before', function () {
        $member = memberSignedIn($this);
        $html = $this->get(route('profile.show'))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"[^>]*wire:name="usarrs-profile-other-sessions"|wire:name="usarrs-profile-other-sessions"[^>]*wire:snapshot="([^"]+)"/', $html, $match);
        $snapshot = html_entity_decode($match[1] ?: $match[2], ENT_QUOTES);

        // Ended from elsewhere while this tab stayed open. refresh(): the
        // guard holds this instance across test requests; a real request
        // would load the member afresh.
        $member->newQuery()->whereKey($member->id)->increment('session_epoch');
        $member->refresh();

        $this->withHeaders(['X-Livewire' => '1'])->postJson(EndpointResolver::updatePath(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'endOthers', 'params' => []]]]],
        ])->assertUnauthorized();

        $this->assertGuest();
        expect($member->fresh()->session_epoch)->toBe(1);
    });

    it('needs a recent confirmation, and ends nothing without one', function () {
        $member = TestUser::factory()->create();
        $this->actingAs($member)->withSession([SessionEpoch::SESSION_KEY => 0]);

        endOthersAs($member)->assertRedirect(route('password.confirm'));

        expect($member->fresh()->session_epoch)->toBe(0);
        otherBrowser($this, $member, 0)->assertOk();
    });

    it('fires OtherSessionsEnded, so an app can revoke its API tokens', function () {
        Event::fake([OtherSessionsEnded::class]);
        $member = memberSignedIn($this);

        endOthersAs($member);

        Event::assertDispatched(OtherSessionsEnded::class, fn (OtherSessionsEnded $event) => $event->user->is($member));
    });

    it('shows the button on the profile', function () {
        memberSignedIn($this);

        $this->get(route('profile.show'))->assertOk()->assertSee('Sign out every other browser');
    });
});

describe('remember-me cookies', function () {
    function recallerFor(TestUser $member): array
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard();

        return [$guard->getRecallerName(), $member->getAuthIdentifier().'|'.$member->getRememberToken().'|'.$guard->hashPasswordForCookie($member->getAuthPassword())];
    }

    /** The next request gets a fresh guard, as a real one would: no user, not logged out. */
    function nextRequest(mixed $test): void
    {
        Auth::forgetGuards();
        $test->flushSession();
    }

    it('stops another device\'s cookie signing it back in, though it did before', function () {
        $member = TestUser::factory()->create(['remember_token' => 'the-old-token-'.str_repeat('x', 46)]);
        [$name, $oldCookie] = recallerFor($member);

        // Control: the old cookie signs in while nothing has been ended.
        $this->withCookie($name, $oldCookie)->get(route('profile.show'))->assertOk();
        $this->assertAuthenticatedAs($member);

        nextRequest($this);
        Auth::guard()->setUser($member);
        SessionEpoch::endOthers($member);

        nextRequest($this);
        $this->withCookie($name, $oldCookie)->get(route('profile.show'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('re-issues this device\'s own cookie with the new token, and that cookie signs in', function () {
        $member = TestUser::factory()->create(['remember_token' => 'the-old-token-'.str_repeat('x', 46)]);
        [$name, $oldCookie] = recallerFor($member);

        /** @var SessionGuard $guard */
        $guard = Auth::guard();
        $guard->setRequest(Request::create('/profile', 'GET', cookies: [$name => $oldCookie]));
        $guard->setUser($member);

        SessionEpoch::endOthers($member);

        $queued = Cookie::queued($name);
        expect($queued)->not->toBeNull()
            ->and($queued->getValue())->not->toBe($oldCookie)
            ->and($queued->getValue())->toContain($member->fresh()->getRememberToken());

        nextRequest($this);
        $this->withCookie($name, $queued->getValue())->get(route('profile.show'))->assertOk();
        $this->assertAuthenticatedAs($member);
    });
});

describe('the stamp', function () {
    it('is put on every sign-in', function () {
        $member = TestUser::factory()->create(['password' => Hash::make('the-password'), 'session_epoch' => 4]);

        Auth::attempt(['email' => $member->email, 'password' => 'the-password']);

        expect(session(SessionEpoch::SESSION_KEY))->toBe(4);
    });

    it('leaves an unstamped session alone while nothing has ever been ended, and stamps it', function () {
        $member = TestUser::factory()->create();
        $this->actingAs($member);

        $this->get(route('profile.show'))->assertOk();

        expect(session(SessionEpoch::SESSION_KEY))->toBe(0);
    });

    it('ends an unstamped session once the epoch has moved, since it can\'t show it is current', function () {
        $member = TestUser::factory()->create(['session_epoch' => 1]);
        $this->actingAs($member);

        $this->get(route('profile.show'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('leaves every session alone while the member has never ended any', function () {
        $member = TestUser::factory()->create();

        otherBrowser($this, $member, 0)->assertOk();
        $this->assertAuthenticatedAs($member);
    });
});

describe('the warning mails', function () {
    it('point the member at signing out every other browser', function (string $mail) {
        $member = TestUser::factory()->create();
        $message = match ($mail) {
            'step-up' => (new StepUpConfirmationLink('https://example.test/confirm', 'Firefox'))->toMail($member),
            'connection' => (new OAuthConnectionNotice('github', true))->toMail($member),
        };

        expect(implode("\n", array_merge($message->introLines, $message->outroLines)))
            ->toContain('sign out every other browser')
            ->toContain(route('profile.show'));
    })->with(['step-up', 'connection']);
});
