<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Usarrs\Http\Controllers\EmailVerificationController;
use Marque\Usarrs\Http\Controllers\LogoutController;
use Marque\Usarrs\Http\Controllers\MagicLinkController;
use Marque\Usarrs\Http\Controllers\PasswordResetController;
use Marque\Usarrs\Http\Controllers\SocialiteController;
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

        // Socialite — the callbacks double as account linking for a user who
        // is already signed in.
        Route::get('auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->name('socialite.redirect');
        Route::get('auth/{provider}/callback', [SocialiteController::class, 'callback'])->name('socialite.callback');
    });

// Logout (requires auth)
Route::middleware(config('usarrs.auth_middleware', ['web', 'auth']))
    ->prefix(config('usarrs.prefix', ''))
    ->group(function () {
        Route::post('logout', LogoutController::class)->name('logout');
    });

// Email verification (requires auth — job #10602 Gap 7 / Spec #96). Route
// names, paths, and the {id}/{hash} param shape are fixed by core Laravel's
// own Illuminate\Auth\Notifications\VerifyEmail, which hardcodes
// 'verification.verify' — not usarrs' choice to make.
Route::middleware(config('usarrs.auth_middleware', ['web', 'auth']))
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
