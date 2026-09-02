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
    | Hide Dead Torrents
    |--------------------------------------------------------------------------
    |
    | When true, torrent listings exclude torrents with no seeders, and the
    | frontends offer a "show dead torrents" toggle to include them.
    |
    | Off by default, deliberately. A torrent has no seeders until its first
    | announce, so enabling this on a catalogue whose torrents have not
    | announced yet — a fresh upload, or any install that has just upgraded —
    | hides them. Turn it on once your tracker is actually serving announces.
    |
    */
    'hide_dead_torrents' => env('TROVE_HIDE_DEAD_TORRENTS', false),
];
