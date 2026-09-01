<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Auth Driver
    |--------------------------------------------------------------------------
    |
    | Controls the authentication UI and flow:
    | - "password": Traditional email + password login
    | - "magic_link": Passwordless email-only login
    | - "socialite": OAuth provider buttons only
    | - "invite_only": Password login, registration disabled (invite-only)
    |
    */

    'auth_driver' => env('USARRS_AUTH_DRIVER', 'password'),

    'socialite_providers' => ['github'],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Off by default. An additional factor layered on top of whichever
    | auth_driver is active — not a driver itself. Add Laravel\Fortify\
    | TwoFactorAuthenticatable to your User model to use it.
    |
    */

    'two_factor' => [
        'enabled' => env('USARRS_2FA_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Passkeys (WebAuthn)
    |--------------------------------------------------------------------------
    |
    | Off by default. An additional credential type layered on top of
    | whichever auth_driver is active — not a driver itself. Add
    | Laravel\Passkeys\PasskeyAuthenticatable and implement
    | Laravel\Passkeys\Contracts\PasskeyUser on your User model to use it.
    |
    | When enabled, Passkeys' own JSON API routes (/passkeys/login,
    | /user/passkeys/*) are left registered — they're WebAuthn-ceremony
    | endpoints usarrs' own UI calls via JS, not a competing login/register
    | page the way Fortify's routes are. When disabled, they're suppressed.
    |
    */

    'passkeys' => [
        'enabled' => env('USARRS_PASSKEYS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invites
    |--------------------------------------------------------------------------
    */

    'invites' => [
        'enabled' => env('USARRS_INVITES_ENABLED', false),
        'required' => env('USARRS_INVITES_REQUIRED', false),
        'max_per_user' => env('USARRS_MAX_INVITES', 3),
        'expiry_days' => env('USARRS_INVITE_EXPIRY', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    'profile' => [
        'show_ratio' => true,
        'show_seedtime' => true,
        'show_announce_key' => true,
        'allow_announce_key_regen' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    'admin' => [
        'enabled' => env('USARRS_ADMIN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'prefix' => env('USARRS_PREFIX', ''),

    // Middleware for guest routes (login, register, password reset)
    'middleware' => ['web'],

    // Middleware for authenticated routes (profile, invite management)
    'auth_middleware' => ['web', 'auth'],

    // Middleware for admin routes
    'admin_middleware' => ['web', 'auth', 'verified'],

    // Layout for Livewire components
    'layout' => env('USARRS_LAYOUT', 'ise::layouts.app'),
];
