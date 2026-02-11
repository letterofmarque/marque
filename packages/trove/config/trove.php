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
];
