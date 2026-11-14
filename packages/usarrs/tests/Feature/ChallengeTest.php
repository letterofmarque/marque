<?php

declare(strict_types=1);

// Job #366. usarrs had no way to put a human check (a captcha) in front of
// register, login or password reset. Apps bind a Challenge; usarrs refuses a
// missing or empty answer itself, so leaving the token out can't bypass it —
// the hole Kosh's non-implicit Turnstile rule had (Kosh Job #176).

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Contracts\Challenge;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Notifications\MagicLinkNotification;
use Marque\Usarrs\Tests\FakeChallenge;
use Marque\Usarrs\Tests\TestUser;

beforeEach(fn () => Notification::fake());

function bindChallenge(array $forms = [Challenge::REGISTER, Challenge::LOGIN, Challenge::PASSWORD_RESET]): FakeChallenge
{
    $challenge = new FakeChallenge($forms);
    app()->instance(Challenge::class, $challenge);

    return $challenge;
}

function registerAnswering(?string $answer): mixed
{
    $component = Livewire::test(Register::class)
        ->set('name', 'New Comer')
        ->set('email', 'newcomer@example.com')
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password');

    if ($answer !== null) {
        $component->set('challenge', $answer);
    }

    return $component->call('register');
}

describe('with nothing bound', function () {
    it('registers, signs in and sends reset links exactly as before', function () {
        registerAnswering(null)->assertHasNoErrors();
        expect(TestUser::where('email', 'newcomer@example.com')->exists())->toBeTrue();
        auth()->logout();

        TestUser::factory()->create(['email' => 'member@example.com', 'password' => bcrypt('right-password')]);
        Livewire::test(Login::class)->set('email', 'member@example.com')->set('password', 'right-password')->call('login')->assertRedirect('/');
        auth()->logout();

        $this->post(route('password.email'), ['email' => 'member@example.com'])->assertSessionHasNoErrors();
        Notification::assertSentTimes(ResetPassword::class, 1);
    });

    it('shows no check on the forms', function () {
        $this->get(route('register'))->assertOk()->assertDontSee('data-usarrs-challenge', escape: false);
        $this->get(route('login'))->assertOk()->assertDontSee('data-usarrs-challenge', escape: false);
        $this->get(route('password.request'))->assertOk()->assertDontSee('data-usarrs-challenge', escape: false);
    });
});

describe('registration', function () {
    it('refuses a submission with no answer, without asking the verifier, and creates and mails nothing', function () {
        $challenge = bindChallenge();

        registerAnswering(null)->assertHasErrors('challenge');

        expect($challenge->verified)->toBe([]);
        expect(TestUser::where('email', 'newcomer@example.com')->exists())->toBeFalse();
        Notification::assertSentTimes(VerifyEmail::class, 0);
    });

    it('refuses a blank answer the same way', function () {
        $challenge = bindChallenge();

        registerAnswering('   ')->assertHasErrors('challenge');

        expect($challenge->verified)->toBe([]);
        expect(TestUser::where('email', 'newcomer@example.com')->exists())->toBeFalse();
    });

    it('refuses an answer the verifier rejects', function () {
        $challenge = bindChallenge();

        registerAnswering('wrong-answer')->assertHasErrors('challenge');

        expect($challenge->verified)->toBe([['answer' => 'wrong-answer', 'form' => 'register']]);
        expect(TestUser::where('email', 'newcomer@example.com')->exists())->toBeFalse();
    });

    it('creates the account when the verifier passes the answer', function () {
        bindChallenge();

        registerAnswering('right-answer')->assertHasNoErrors();

        expect(TestUser::where('email', 'newcomer@example.com')->exists())->toBeTrue();
    });

    it('clears a used answer and tells the widget to reset, since a token works once', function () {
        bindChallenge();

        registerAnswering('wrong-answer')
            ->assertSet('challenge', '')
            ->assertDispatched('usarrs-challenge-reset');
    });

    it('does not count a refused check against the IP limit, so bots do not use up their neighbours\' sign-ups', function () {
        bindChallenge();
        config()->set('usarrs.limits.registrations_per_ip', 1);

        registerAnswering(null)->assertHasErrors('challenge');
        registerAnswering('right-answer')->assertHasNoErrors();

        expect(TestUser::where('email', 'newcomer@example.com')->exists())->toBeTrue();
    });

    it('shows the widget and the answer input on the form', function () {
        bindChallenge();

        $this->get(route('register'))->assertOk()
            ->assertSee('<div class="fake-challenge" data-form="register"></div>', escape: false)
            ->assertSee('data-usarrs-challenge', escape: false);
    });
});

describe('login', function () {
    beforeEach(function () {
        $this->member = TestUser::factory()->create(['email' => 'member@example.com', 'password' => bcrypt('right-password')]);
    });

    it('refuses the right password with no answer, without asking the verifier', function () {
        $challenge = bindChallenge();

        Livewire::test(Login::class)->set('email', 'member@example.com')->set('password', 'right-password')->call('login')
            ->assertHasErrors('challenge');

        $this->assertGuest();
        expect($challenge->verified)->toBe([]);
    });

    it('refuses the right password with an answer the verifier rejects', function () {
        $challenge = bindChallenge();

        Livewire::test(Login::class)->set('email', 'member@example.com')->set('password', 'right-password')
            ->set('challenge', 'wrong-answer')->call('login')->assertHasErrors('challenge');

        $this->assertGuest();
        expect($challenge->verified)->toBe([['answer' => 'wrong-answer', 'form' => 'login']]);
    });

    it('signs in with the right password and a passing answer', function () {
        bindChallenge();

        Livewire::test(Login::class)->set('email', 'member@example.com')->set('password', 'right-password')
            ->set('challenge', 'right-answer')->call('login')->assertRedirect('/');

        $this->assertAuthenticatedAs($this->member);
    });

    it('sends no sign-in link without an answer', function () {
        config()->set('usarrs.auth_driver', 'magic_link');
        bindChallenge();

        Livewire::test(Login::class)->set('email', 'member@example.com')->call('login')->assertHasErrors('challenge');

        Notification::assertNotSentTo($this->member, MagicLinkNotification::class);
    });

    it('sends the sign-in link with a passing answer', function () {
        config()->set('usarrs.auth_driver', 'magic_link');
        bindChallenge();

        Livewire::test(Login::class)->set('email', 'member@example.com')->set('challenge', 'right-answer')->call('login')
            ->assertHasNoErrors();

        Notification::assertSentToTimes($this->member, MagicLinkNotification::class, 1);
    });

    it('asks nothing on a form the challenge does not protect', function () {
        $challenge = bindChallenge([Challenge::REGISTER]);

        Livewire::test(Login::class)->set('email', 'member@example.com')->set('password', 'right-password')->call('login')
            ->assertRedirect('/');

        expect($challenge->verified)->toBe([]);
        auth()->logout();
        $this->get(route('register'))->assertSee('data-usarrs-challenge', escape: false);
        $this->get(route('login'))->assertDontSee('data-usarrs-challenge', escape: false);
    });
});

describe('password reset', function () {
    beforeEach(function () {
        $this->member = TestUser::factory()->create(['email' => 'member@example.com']);
    });

    it('refuses a post with no challenge field at all, without asking the verifier, and sends nothing', function () {
        $challenge = bindChallenge();

        $this->post(route('password.email'), ['email' => 'member@example.com'])->assertSessionHasErrors('challenge');

        expect($challenge->verified)->toBe([]);
        Notification::assertNotSentTo($this->member, ResetPassword::class);
    });

    it('refuses a non-string answer the same way', function () {
        $challenge = bindChallenge();

        $this->post(route('password.email'), ['email' => 'member@example.com', 'challenge' => ['right-answer']])
            ->assertSessionHasErrors('challenge');

        expect($challenge->verified)->toBe([]);
    });

    it('refuses an answer the verifier rejects', function () {
        bindChallenge();

        $this->post(route('password.email'), ['email' => 'member@example.com', 'challenge' => 'wrong-answer'])
            ->assertSessionHasErrors('challenge');

        Notification::assertNotSentTo($this->member, ResetPassword::class);
    });

    it('sends the link with a passing answer', function () {
        bindChallenge();

        $this->post(route('password.email'), ['email' => 'member@example.com', 'challenge' => 'right-answer'])
            ->assertSessionHasNoErrors();

        Notification::assertSentToTimes($this->member, ResetPassword::class, 1);
    });

    it('shows the widget and a posted answer input on the form', function () {
        bindChallenge();

        $this->get(route('password.request'))->assertOk()
            ->assertSee('data-form="password-reset"', escape: false)
            ->assertSee('name="challenge"', escape: false);
    });
});
