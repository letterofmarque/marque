<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Applied to the panel and to every screen packages register. Matches the
    | convention usarrs and disguise already use for their own admin routes.
    |
    | Authorisation is NOT handled here. Middleware decides who is let through
    | the door; the minimum role a screen declares is enforced separately,
    | against the registry entry itself, so the panel's navigation and its route
    | resolution can never disagree about who may see what.
    |
    */

    'middleware' => ['web', 'auth', 'verified'],

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    |
    | The panel lives at /admin by default. A screen's own `path` is registered
    | as given, so this prefixes the panel index only — a package that wants its
    | screen somewhere else says so when it registers.
    |
    */

    'prefix' => env('SKIPPER_PREFIX', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Panel title
    |--------------------------------------------------------------------------
    */

    'title' => env('SKIPPER_TITLE', 'Administration'),

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    |
    | The layout the panel extends. Defaults to deck's shell; point it at your
    | own to reshape the chrome without forking the panel.
    |
    */

    'layout' => 'deck::layouts.app',

    /*
    |--------------------------------------------------------------------------
    | Default group
    |--------------------------------------------------------------------------
    |
    | Heading for screens that declare no group of their own. They have to land
    | somewhere rather than vanishing from the listing.
    |
    */

    'default_group' => 'General',

];
