<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Deck App Shell Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the shared app layout.
    |
    */

    'app_name' => env('APP_NAME', 'Marque'),

    // Theme variants: planned, not built, and nothing reads this key yet.
    // check-docs: ignore — placeholder, read by nothing until #10815 decides it
    'theme' => env('DECK_THEME', 'default'),

    'show_footer' => true,
];
