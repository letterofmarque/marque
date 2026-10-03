<?php

declare(strict_types=1);

// Issue #10857. A user whose status is banned, disabled or pending used to sign
// in normally by every path, and an existing session kept working:
// EnsureUserIsActive existed but was registered nowhere. Now the login seam
// refuses them, a Login listener refuses sign-ins that bypass the seam
// (passkeys, remember-me), and the middleware ends a live session on its next
// request.

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Http\Middleware\EnsureUserIsActive;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Tests\TestUser;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;

dataset('inactive statuses', [
    'banned' => ['banned', 'This account has been banned.'],
    'disabled' => ['disabled', 'This account has been disabled.'],
    'pending' => ['pending', 'This account is awaiting approval.'],
]);

describe('the login seam', function () {
    it('refuses an inactive user and signs no one in', function (string $status, string $message) {
        $user = TestUser::factory()->create(['status' => $status]);

        $destination = app(LoginCompletion::class)->begin($user, remember: false);

        expect($destination)->toBe(route('login'));
        $this->assertGuest();
        expect(session('errors')->first('email'))->toBe($message);
    })->with('inactive statuses');

    it('refuses before the two-factor challenge, so a banned user never reaches it', function () {
        config()->set('usarrs.two_factor.enabled', true);
        $user = TestUser::factory()->create([
            'status' => 'banned',
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ]);

        $destination = app(LoginCompletion::class)->begin($user, remember: false);

        expect($destination)->toBe(route('login'))
            ->and(session()->has('login.id'))->toBeFalse();
        $this->assertGuest();
    });

    it('signs in an active user as before', function () {
        $user = TestUser::factory()->create(['status' => 'active']);

        app(LoginCompletion::class)->begin($user, remember: false);

        $this->assertAuthenticatedAs($user);
    });

    it('refuses a status it does not recognise, rather than letting it through', function () {
        $user = TestUser::factory()->create(['status' => 'frozen']);

        app(LoginCompletion::class)->begin($user, remember: false);

        $this->assertGuest();
    });
});

describe('password login', function () {
    it('refuses a banned user with correct credentials and says why', function () {
        TestUser::factory()->create(['email' => 'banned@example.com', 'status' => 'banned']);

        Livewire::test(Login::class)
            ->set('email', 'banned@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $this->get(route('login'))->assertSee('This account has been banned.');
    });
});

describe('magic link', function () {
    beforeEach(fn () => config()->set('usarrs.auth_driver', 'magic_link'));

    it('refuses a banned user holding a valid link', function () {
        $user = TestUser::factory()->create(['status' => 'banned']);
        $token = app('auth.password.broker')->createToken($user);

        $this->get(route('magic-link.verify', ['token' => $token, 'email' => $user->email]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });
});

describe('sign-ins outside the seam', function () {
    // laravel/passkeys signs in through its own endpoint, and a remember-me
    // cookie re-authenticates without any usarrs code running. Both fire the
    // Login event, which is where they are caught.
    it('logs out and refuses an inactive user signed in by any other route', function () {
        $user = TestUser::factory()->create(['status' => 'banned']);

        expect(fn () => Auth::login($user))->toThrow(HttpException::class, 'This account has been banned.');

        $this->assertGuest();
    });

    it('leaves an active user signed in', function () {
        $user = TestUser::factory()->create();

        Auth::login($user);

        $this->assertAuthenticatedAs($user);
    });
});

describe('a live session', function () {
    beforeEach(function () {
        Route::middleware('web')->get('/_test/app-page', fn () => 'app page');
    });

    it('is ended on its next request to a usarrs page once the user is banned', function () {
        $user = TestUser::factory()->create();
        $this->actingAs($user)->get(route('profile.show'))->assertOk();

        $user->forceFill(['status' => 'banned'])->save();

        $this->get(route('profile.show'))->assertForbidden();
        $this->assertGuest();
    });

    it('is ended on any web page, not only usarrs\' own — a ban is not scoped to one package', function () {
        $user = TestUser::factory()->create(['status' => 'disabled']);

        $this->actingAs($user)->get('/_test/app-page')->assertForbidden();

        $this->assertGuest();
    });

    it('is untouched for an active user', function () {
        $this->actingAs(TestUser::factory()->create())->get('/_test/app-page')->assertOk();
    });

    it('covers Livewire updates from a tab opened before the ban, which run in the web group', function () {
        expect(app('router')->getMiddlewareGroups()['web'])->toContain(EnsureUserIsActive::class);
    });
});
