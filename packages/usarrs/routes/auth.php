<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Usarrs\Http\Controllers\EmailVerificationController;
use Marque\Usarrs\Http\Controllers\LogoutController;
use Marque\Usarrs\Http\Controllers\MagicLinkController;
use Marque\Usarrs\Http\Controllers\PasswordResetController;
use Marque\Usarrs\Http\Controllers\SocialiteController;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Livewire\Auth\TwoFactorChallenge;

/*
|--------------------------------------------------------------------------
| Auth Routes
|--------------------------------------------------------------------------
*/

// Guest routes
Route::middleware(config('usarrs.middleware', ['web']))
    ->prefix(config('usarrs.prefix', ''))
    ->group(function () {
        Route::get('login', Login::class)->name('login');
        Route::get('register', Register::class)->name('register');
        Route::get('two-factor-challenge', TwoFactorChallenge::class)->name('two-factor.login');

        // Password reset
        Route::get('forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
        Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
        Route::get('reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.update');

        // Magic link
        Route::get('auth/magic-link/sent', [MagicLinkController::class, 'showSentPage'])->name('magic-link.sent');
        Route::get('auth/magic-link/verify', [MagicLinkController::class, 'verify'])->name('magic-link.verify');

        // Socialite
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
    });
