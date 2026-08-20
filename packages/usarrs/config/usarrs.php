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
        'show_passkey' => true,
        'allow_passkey_regen' => true,
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
