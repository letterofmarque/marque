<?php

declare(strict_types=1);

// Job #366. Sign-up and the mail a visitor can make usarrs send were
// unlimited: every account mails a verification link to the address typed,
// and sign-in links, reset links and verification resends could be asked for
// as often as anyone liked. A script could mail strangers through the
// operator's mail provider without limit.

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Notifications\MagicLinkNotification;
use Marque\Usarrs\Tests\TestUser;

beforeEach(fn () => Notification::fake());

function signUp(string $email): mixed
{
    return Livewire::test(Register::class)
        ->set('name', 'New Comer')
        ->set('email', $email)
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->call('register');
}

function askForSignInLink(string $email): mixed
{
    return Livewire::test(Login::class)->set('email', $email)->call('login');
}

describe('registration', function () {
    it('refuses the sixth account from one IP within the hour, and creates and mails nothing for it', function () {
        foreach (range(1, 5) as $n) {
            signUp("newcomer{$n}@example.com")->assertHasNoErrors();
            auth()->logout();
        }

        signUp('newcomer6@example.com')->assertHasErrors('email');

        expect(TestUser::where('email', 'newcomer6@example.com')->exists())->toBeFalse();
        Notification::assertSentTimes(VerifyEmail::class, 5);
    });

    it('tells the visitor how long to wait, in minutes', function () {
        config()->set('usarrs.limits.registrations_per_ip', 1);
        signUp('first@example.com');
        auth()->logout();

        expect(signUp('second@example.com')->errors()->first('email'))->toContain('Too many attempts')->toContain('minutes');
    });

    it('does not count a form that fails validation, so typos do not use the limit up', function () {
        config()->set('usarrs.limits.registrations_per_ip', 1);

        signUp('not-an-address')->assertHasErrors('email');
        signUp('ok@example.com')->assertHasNoErrors();

        expect(TestUser::where('email', 'ok@example.com')->exists())->toBeTrue();
    });

    it('follows usarrs.limits.registrations_per_ip, and 0 turns it off', function () {
        config()->set('usarrs.limits.registrations_per_ip', 0);

        foreach (range(1, 8) as $n) {
            signUp("many{$n}@example.com")->assertHasNoErrors();
            auth()->logout();
        }

        expect(TestUser::where('email', 'like', 'many%')->count())->toBe(8);
    });

    it('counts before creating, as an atomic increment, so the count is on record even for the refused one', function () {
        config()->set('usarrs.limits.registrations_per_ip', 1);
        signUp('first@example.com');
        auth()->logout();
        signUp('second@example.com');

        expect(RateLimiter::attempts('usarrs.register:127.0.0.1'))->toBe(2);
    });
});

describe('sign-in links', function () {
    beforeEach(fn () => config()->set('usarrs.auth_driver', 'magic_link'));

    it('sends one link a minute per address, and nothing for the second ask', function () {
        $member = TestUser::factory()->create(['email' => 'member@example.com']);

        askForSignInLink('member@example.com')->assertHasNoErrors();
        askForSignInLink('member@example.com')->assertHasErrors('email');

        Notification::assertSentToTimes($member, MagicLinkNotification::class, 1);
    });

    it('treats the address case-insensitively, so MEMBER@ does not get a second link', function () {
        $member = TestUser::factory()->create(['email' => 'member@example.com']);

        askForSignInLink('member@example.com');
        askForSignInLink('MEMBER@example.com')->assertHasErrors('email');

        Notification::assertSentToTimes($member, MagicLinkNotification::class, 1);
    });

    it('refuses the eleventh ask from one IP within the hour, even for a fresh address', function () {
        foreach (range(1, 10) as $n) {
            TestUser::factory()->create(['email' => "member{$n}@example.com"]);
            askForSignInLink("member{$n}@example.com")->assertHasNoErrors();
        }

        $eleventh = TestUser::factory()->create(['email' => 'member11@example.com']);
        askForSignInLink('member11@example.com')->assertHasErrors('email');

        Notification::assertNotSentTo($eleventh, MagicLinkNotification::class);
    });

    it('counts an address with no account the same as one with, so the limit reveals nothing', function () {
        askForSignInLink('nobody@example.com')->assertHasNoErrors();
        askForSignInLink('nobody@example.com')->assertHasErrors('email');

        TestUser::factory()->create(['email' => 'member@example.com']);
        askForSignInLink('member@example.com')->assertHasNoErrors();
        askForSignInLink('member@example.com')->assertHasErrors('email');
    });

    it('follows usarrs.limits.sign_in_links_per_ip', function () {
        config()->set('usarrs.limits.sign_in_links_per_ip', 2);

        askForSignInLink('a@example.com')->assertHasNoErrors();
        askForSignInLink('b@example.com')->assertHasNoErrors();
        askForSignInLink('c@example.com')->assertHasErrors('email');
    });
});

describe('password reset links', function () {
    it('refuses the eleventh ask from one IP within the hour, and sends nothing for it', function () {
        foreach (range(1, 10) as $n) {
            TestUser::factory()->create(['email' => "member{$n}@example.com"]);
            $this->post(route('password.email'), ['email' => "member{$n}@example.com"])->assertSessionHasNoErrors();
        }

        $eleventh = TestUser::factory()->create(['email' => 'member11@example.com']);
        $this->post(route('password.email'), ['email' => 'member11@example.com'])->assertSessionHasErrors('email');

        Notification::assertNotSentTo($eleventh, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 10);
    });

    it('limits per IP, so a visitor on another address is unaffected', function () {
        config()->set('usarrs.limits.reset_links_per_ip', 1);
        $first = TestUser::factory()->create(['email' => 'first@example.com']);
        $second = TestUser::factory()->create(['email' => 'second@example.com']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->post(route('password.email'), ['email' => 'first@example.com'])->assertSessionHasNoErrors();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->post(route('password.email'), ['email' => 'second@example.com'])->assertSessionHasErrors('email');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(route('password.email'), ['email' => 'second@example.com'])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($first, ResetPassword::class, 1);
        Notification::assertSentToTimes($second, ResetPassword::class, 1);
    });

    it('counts an address with no account, so walking a list of guesses is limited too', function () {
        config()->set('usarrs.limits.reset_links_per_ip', 2);

        $this->post(route('password.email'), ['email' => 'guess1@example.com'])->assertSessionHasNoErrors();
        $this->post(route('password.email'), ['email' => 'guess2@example.com'])->assertSessionHasNoErrors();
        $this->post(route('password.email'), ['email' => 'guess3@example.com'])->assertSessionHasErrors('email');
    });
});

describe('verification resend', function () {
    it('sends one a minute per member, and says to wait instead of sending the second', function () {
        $member = TestUser::factory()->unverified()->create();

        $this->actingAs($member)->post(route('verification.send'))->assertSessionHas('status');
        $this->actingAs($member)->post(route('verification.send'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Too many attempts'));

        Notification::assertSentToTimes($member, VerifyEmail::class, 1);
    });

    it('limits per member, so one member resending does not hold back another', function () {
        $first = TestUser::factory()->unverified()->create();
        $second = TestUser::factory()->unverified()->create();

        $this->actingAs($first)->post(route('verification.send'));
        // auth.session would sign the second member out against the first's
        // password hash, so start the second on a clean session.
        $this->flushSession();
        $this->actingAs($second)->post(route('verification.send'));

        Notification::assertSentToTimes($second, VerifyEmail::class, 1);
    });
});
