<?php

declare(strict_types=1);

// Job #355 step 1. password.confirm was usarrs' only step-up, and its page
// asked for a password. Under socialite mode an account's password is random
// and unknown (SocialiteController::register); under magic_link the member
// never chose one. So nothing behind password.confirm (managing passkeys, and
// the step-ups Job #355 adds) could be reached by those members at all.
//
// Confirming now offers what the member has: their password where passwords
// sign in, an emailed link to a verified address for anyone, a connected
// provider (SocialiteDriver/OAuthStepUpTest) or a passkey
// (PasskeysEnabled/PasskeyStepUpTest).

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Marque\Usarrs\Auth\EmailStepUp;
use Marque\Usarrs\Livewire\Auth\ConfirmByEmail;
use Marque\Usarrs\Livewire\Auth\PasswordConfirm;
use Marque\Usarrs\Notifications\StepUpConfirmationLink;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();

    Route::middleware(['web', 'auth', 'password.confirm'])
        ->get('/test-confirm-only', fn () => 'ok');
});

/** Ask for the link and return the URL it carries. */
function requestStepUpLink(TestUser $user): string
{
    Livewire::actingAs($user)->test(PasswordConfirm::class)->call('sendLink')->assertHasNoErrors();

    $url = null;
    Notification::assertSentTo($user, StepUpConfirmationLink::class, function ($n) use (&$url) {
        $url = $n->url;

        return true;
    });

    return $url;
}

function tokenFrom(string $url): string
{
    return basename(parse_url($url, PHP_URL_PATH));
}

it('offers the password only where a password signs in', function (string $driver, bool $offered) {
    config(['usarrs.auth_driver' => $driver]);
    $user = TestUser::factory()->create();

    $page = $this->actingAs($user)->get(route('password.confirm'))->assertOk();

    $offered
        ? $page->assertSee('type="password"', escape: false)
        : $page->assertDontSee('type="password"', escape: false);
})->with([
    'password' => ['password', true],
    'invite_only' => ['invite_only', true],
    'magic_link' => ['magic_link', false],
    'socialite' => ['socialite', false],
]);

it('refuses a password confirmation where passwords don\'t sign in', function () {
    // A password set from the profile under magic_link or socialite was never a
    // way in, and may be a squatter's (#10880).
    config(['usarrs.auth_driver' => 'magic_link']);
    $user = TestUser::factory()->create(['password' => bcrypt('known-password')]);

    Livewire::actingAs($user)->test(PasswordConfirm::class)
        ->set('password', 'known-password')
        ->call('confirm')
        ->assertHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('offers an emailed link on every driver', function (string $driver) {
    config(['usarrs.auth_driver' => $driver]);
    $user = TestUser::factory()->create();

    $this->actingAs($user)->get(route('password.confirm'))
        ->assertOk()
        ->assertSee(__('Email me a link'));
})->with(['password', 'invite_only', 'magic_link', 'socialite']);

it('lets a magic-link member pass password.confirm by the emailed link', function () {
    config(['usarrs.auth_driver' => 'magic_link']);
    $user = TestUser::factory()->create();
    $this->actingAs($user)->get('/test-confirm-only')->assertRedirect(route('password.confirm'));

    $url = requestStepUpLink($user);

    // Opening the link (from any device, signed in or not) approves nothing.
    auth()->logout();
    $this->get($url)->assertOk()->assertSee(__('Confirm it\'s you'));
    Livewire::test(ConfirmByEmail::class, ['token' => tokenFrom($url)])->call('approve')->assertHasNoErrors();

    // The session that asked picks the approval up.
    Livewire::actingAs($user)->test(PasswordConfirm::class)
        ->call('checkLink')
        ->assertRedirect(url('/test-confirm-only'));

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
    $this->actingAs($user)->get('/test-confirm-only')->assertOk();
});

it('confirms nothing until the link\'s page is acted on', function () {
    // A mail scanner follows links. Opening the page must not be the approval.
    $user = TestUser::factory()->create();
    $url = requestStepUpLink($user);

    $this->get($url)->assertOk();

    Livewire::actingAs($user)->test(PasswordConfirm::class)->call('checkLink')->assertNoRedirect();
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('approves only the session that asked', function () {
    // The approval is for the session holding the token, not any session of
    // that member: a thief's session, asking on its own, gets its own link
    // in the owner's inbox.
    $user = TestUser::factory()->create();
    $url = requestStepUpLink($user);
    Livewire::test(ConfirmByEmail::class, ['token' => tokenFrom($url)])->call('approve');

    session()->forget('usarrs.step-up.token');

    Livewire::actingAs($user)->test(PasswordConfirm::class)->call('checkLink')->assertNoRedirect();
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('does not let one member\'s approval confirm another member\'s session', function () {
    $owner = TestUser::factory()->create();
    $other = TestUser::factory()->create();
    $url = requestStepUpLink($owner);
    Livewire::test(ConfirmByEmail::class, ['token' => tokenFrom($url)])->call('approve');

    Livewire::actingAs($other)->test(PasswordConfirm::class)->call('checkLink')->assertNoRedirect();
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('works once', function () {
    $user = TestUser::factory()->create();
    $url = requestStepUpLink($user);
    Livewire::test(ConfirmByEmail::class, ['token' => tokenFrom($url)])->call('approve');
    Livewire::actingAs($user)->test(PasswordConfirm::class)->call('checkLink')->assertRedirect();

    session()->forget('auth.password_confirmed_at');
    Livewire::test(ConfirmByEmail::class, ['token' => tokenFrom($url)])->call('approve')->assertHasErrors('token');
    Livewire::actingAs($user)->test(PasswordConfirm::class)->call('checkLink')->assertNoRedirect();

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('refuses an unsigned or tampered link', function () {
    $user = TestUser::factory()->create();
    $url = requestStepUpLink($user);

    $this->get(route('password.confirm.email', ['token' => tokenFrom($url)]))->assertForbidden();
    // A different first character, always: 'x' alone left a token that began
    // with 'x' untouched, and the valid link answered 200 (1 run in 62).
    $token = tokenFrom($url);
    $tampered = ($token[0] === 'x' ? 'y' : 'x').substr($token, 1);
    $this->get(str_replace($token, $tampered, $url))->assertForbidden();
});

it('does not offer or send a link to an address nobody has proven', function () {
    // A thief holding the session can change the address to their own; the new
    // one is unverified until they prove it (CP #776), and until then it is no
    // proof of anything.
    $user = TestUser::factory()->unverified()->create();

    $this->actingAs($user)->get(route('password.confirm'))->assertDontSee(__('Email me a link'));

    Livewire::actingAs($user)->test(PasswordConfirm::class)->call('sendLink')->assertHasErrors('link');
    Notification::assertNothingSent();
});

it('sends at most one link a minute', function () {
    $user = TestUser::factory()->create();

    Livewire::actingAs($user)->test(PasswordConfirm::class)->call('sendLink')->assertHasNoErrors();
    Livewire::actingAs($user)->test(PasswordConfirm::class)->call('sendLink')->assertHasErrors('link');

    Notification::assertSentToTimes($user, StepUpConfirmationLink::class, 1);
});

it('expires', function () {
    $user = TestUser::factory()->create();
    $url = requestStepUpLink($user);

    $this->travel(16)->minutes();

    $this->get($url)->assertForbidden();
});

it('names the browser that asked on the link\'s page', function () {
    // So an owner who didn't ask can tell, and doesn't approve a thief.
    $user = TestUser::factory()->create();
    $this->actingAs($user);

    $url = EmailStepUp::request($user, 'TestBrowser/1.0');

    auth()->logout();
    $this->get($url)->assertOk()->assertSee('TestBrowser/1.0');
});

it('builds a link that URL::hasValidSignature accepts', function () {
    $user = TestUser::factory()->create();
    $url = requestStepUpLink($user);

    expect(URL::hasValidSignature(request()->create($url)))->toBeTrue();
});

it('tells an unverified member with nothing else to confirm with to verify first', function () {
    // From the independent check: under socialite with no connection, an
    // unproven address left a confirm page offering nothing at all.
    config(['usarrs.auth_driver' => 'socialite']);
    $user = TestUser::factory()->unverified()->create();

    $this->actingAs($user)->get(route('password.confirm'))
        ->assertOk()
        ->assertSee(__('Verify your email address first'))
        ->assertSee(route('verification.notice'));
});
