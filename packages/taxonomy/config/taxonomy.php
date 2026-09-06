<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Definition paths
    |--------------------------------------------------------------------------
    |
    | Where content-type definitions are read from. Packages ship versioned
    | defaults; the app directory overrides them by content-type name (Spec
    | #103 — "definitions load from both packages and the app; the app wins").
    |
    | Empty until CP3 builds the loader.
    |
    */

    'definitions' => [
        'path' => config_path('taxonomies'),
    ],

];
