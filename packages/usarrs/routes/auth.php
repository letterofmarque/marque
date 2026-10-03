<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;
use Marque\Usarrs\Enums\AuthDriver;
use Marque\Usarrs\Http\Controllers\EmailVerificationController;
use Marque\Usarrs\Http\Controllers\LogoutController;
use Marque\Usarrs\Http\Controllers\MagicLinkController;
use Marque\Usarrs\Http\Controllers\PasswordResetController;
use Marque\Usarrs\Http\Controllers\SocialiteController;
use Marque\Usarrs\Livewire\Auth\ConfirmOAuthLink;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\PasswordConfirm;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Livewire\Auth\TwoFactorChallenge;

/*
|--------------------------------------------------------------------------
| Auth Routes
|--------------------------------------------------------------------------
*/

// Guest-only routes — a logged-in user has no business on any of these, and
// `guest` redirects them home instead (job #10698).
//
// This group used to hold everything below as well, under plain ['web'], so
// an authenticated user could open the login form. The split exists because
// a single config key could not express the difference: the routes in the
// second group have real authenticated uses, and gating them would break a
// reset link followed while logged in, a magic link clicked on another
// device, and OAuth account linking.
Route::middleware(config('usarrs.guest_middleware', ['web', 'guest']))
    ->prefix(config('usarrs.prefix', ''))
    ->group(function () {
        Route::get('login', Login::class)->name('login');
        Route::get('register', Register::class)->name('register');
        Route::get('two-factor-challenge', TwoFactorChallenge::class)->name('two-factor.login');

        // Requesting a reset mail is the "I forgot my password" flow, which a
        // logged-in user is by definition not in. The authenticated
        // equivalent is password.confirm, registered further down.
        Route::get('forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
        Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
    });

// Reachable by anyone — guest OR authenticated. See the note above.
Route::middleware(config('usarrs.middleware', ['web']))
    ->prefix(config('usarrs.prefix', ''))
    ->group(function () {
        // Consuming the reset link itself: arrives from email, and the
        // recipient may well already be logged in on that browser.
        Route::get('reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.update');

        // Magic link
        Route::get('auth/magic-link/sent', [MagicLinkController::class, 'showSentPage'])->name('magic-link.sent');
        Route::get('auth/magic-link/verify', [MagicLinkController::class, 'verify'])->name('magic-link.verify');

        // Socialite — only under socialite mode (Spec #142). These used to be
        // registered under every mode and gated only on socialite_providers,
        // which defaults to ['github'], so a password-mode install had a live
        // OAuth door it never chose. The callbacks double as account linking
        // for a user who is already signed in.
        if (AuthDriver::from(config('usarrs.auth_driver', 'password')) === AuthDriver::Socialite) {
            // auth.session: a signed-in session whose password hash has rotated
            // under it — a squatter's, once the owner proved the inbox — is
            // ended here, not allowed to connect another provider (CP #776).
            Route::get('auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->middleware('auth.session')->name('socialite.redirect');
            Route::get('auth/{provider}/callback', [SocialiteController::class, 'callback'])->middleware('auth.session')->name('socialite.callback');

            // The emailed "connect this account?" link — signed and expiring.
            // It opens a page naming the provider account; connecting is a
            // deliberate action there, and works once (Spec #142, CP #762).
            Route::get('auth/{provider}/link/{token}', ConfirmOAuthLink::class)
                ->middleware('signed')
                ->name('socialite.link.confirm');
        }
    });

// Logout (requires auth)
Route::middleware([...config('usarrs.auth_middleware', ['web', 'auth']), 'auth.session'])
    ->prefix(config('usarrs.prefix', ''))
    ->group(function () {
        Route::post('logout', LogoutController::class)->name('logout');
    });

// Email verification (requires auth — job #10602 Gap 7 / Spec #96). Route
// names, paths, and the {id}/{hash} param shape are fixed by core Laravel's
// own Illuminate\Auth\Notifications\VerifyEmail, which hardcodes
// 'verification.verify' — not usarrs' choice to make.
Route::middleware([...config('usarrs.auth_middleware', ['web', 'auth']), 'auth.session'])
    ->prefix(config('usarrs.prefix', ''))
    ->group(function () {
        Route::get('email/verify', [EmailVerificationController::class, 'notice'])
            ->name('verification.notice');

        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('signed')
            ->name('verification.verify');

        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
            ->name('verification.send');

        Route::get('user/confirm-password', PasswordConfirm::class)->name('password.confirm');
    });

// Passkeys — laravel/passkeys' WebAuthn endpoints, registered here rather than
// by laravel/passkeys itself (#10883). Same paths, names and controllers, with
// usarrs' middleware: auth.session throughout, so a session ended by a rotated
// password hash can't add a passkey (CP #784); a verified address to add one
// (#10879) and a confirmed password to manage them; and a throttle that
// Fortify 1.39 would otherwise have removed.
if (config('usarrs.passkeys.enabled', false)) {
    Route::middleware([...config('usarrs.middleware', ['web']), 'auth.session'])
        ->prefix(config('usarrs.prefix', ''))
        ->group(function () {
            $throttle = 'throttle:6,1';

            Route::middleware(['guest', $throttle])->group(function () {
                Route::get('passkeys/login/options', [PasskeyLoginController::class, 'index'])->name('passkey.login-options');
                Route::post('passkeys/login', [PasskeyLoginController::class, 'store'])->name('passkey.login');
            });

            Route::middleware('auth')->group(function () use ($throttle) {
                Route::get('passkeys/confirm/options', [PasskeyConfirmationController::class, 'index'])->middleware($throttle)->name('passkey.confirm-options');
                Route::post('passkeys/confirm', [PasskeyConfirmationController::class, 'store'])->middleware($throttle)->name('passkey.confirm');

                Route::get('user/passkeys/options', [PasskeyRegistrationController::class, 'index'])->middleware(['verified', 'password.confirm', $throttle])->name('passkey.registration-options');
                Route::post('user/passkeys', [PasskeyRegistrationController::class, 'store'])->middleware(['verified', 'password.confirm', $throttle])->name('passkey.store');
                Route::delete('user/passkeys/{passkey}', [PasskeyRegistrationController::class, 'destroy'])->middleware('password.confirm')->name('passkey.destroy');
            });
        });
}
