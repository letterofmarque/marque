<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Id App Shell Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the shared app layout.
    |
    */

    'app_name' => env('APP_NAME', 'Marque'),

    // Future: support theme variants
    'theme' => env('ID_THEME', 'default'),

    'show_footer' => true,
];
