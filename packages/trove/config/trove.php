<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Trove Configuration
    |--------------------------------------------------------------------------
    |
    | Core configuration for Marque Trove.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The User model class that Trove should use. This model should implement
    | the Marque\Trove\Contracts\UserInterface interface.
    |
    */
    'user_model' => env('TROVE_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The storage disk to use for storing torrent files.
    |
    */
    'storage_disk' => env('TROVE_STORAGE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Ratio Tracking
    |--------------------------------------------------------------------------
    |
    | Controls how user ratios are tracked.
    |
    | 'full' - Track upload/download bytes, enforce ratio requirements
    | 'off' - No tracking at all
    | 'seedtime' - Track seeding time instead (ratioless)
    |
    */
    'ratio_mode' => env('TROVE_RATIO_MODE', 'full'),

    // Minimum ratio required (only applies when ratio_mode = 'full')
    'min_ratio' => env('TROVE_MIN_RATIO', 0.5),

    // Minimum seed time in seconds (only applies when ratio_mode = 'seedtime')
    'min_seedtime' => env('TROVE_MIN_SEEDTIME', 86400), // 24 hours
];
