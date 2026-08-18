<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Forum
    |--------------------------------------------------------------------------
    |
    | Comments and the forum share one set of tables, so this is a UI and route
    | toggle rather than an install choice — turning it off costs nothing and
    | leaves torrent comments working.
    |
    | A deployment that only wants comments under torrents sets this to false.
    |
    */

    'forum' => [
        'enabled' => env('PARLEY_FORUM', true),
        'prefix' => 'forum',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Comments
    |--------------------------------------------------------------------------
    |
    | Comment threads attach to any model. They have no title and no category —
    | the thing they are attached to is their subject.
    |
    */

    'comments' => [
        'enabled' => env('PARLEY_COMMENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Loading
    |--------------------------------------------------------------------------
    |
    | Threads load continuously rather than by page. Private trackers are one of
    | the last strongholds of desktop use, so the mobile-first case for
    | pagination does not apply — and "page 2 of a nested thread" is not a
    | well-defined thing anyway.
    |
    | These are batch sizes for continuous loading, not page sizes.
    |
    */

    'loading' => [
        'threads' => 25,
        'posts' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Nesting
    |--------------------------------------------------------------------------
    |
    | Replies nest to arbitrary depth in storage — there is deliberately no cap
    | on reply_to_id. This value governs INDENTATION ONLY: past this depth the
    | UI stops indenting and offers a "continue thread" affordance instead.
    |
    | Changing it is a display decision, never a migration. That separation is
    | the whole reason the cap lives here rather than in the schema.
    |
    */

    'nesting' => [
        'indent_depth' => 5,
        'collapsible' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Text Format
    |--------------------------------------------------------------------------
    |
    | Post bodies go through marque/squidink. Parley does not decide or
    | implement a text format — it stores what squidink stores, so posts and
    | torrent descriptions behave identically.
    |
    | Each post records the parser that wrote it, so changing this affects new
    | posts only and existing content keeps rendering correctly. Null means
    | "whatever squidink is configured to default to".
    |
    */

    'format' => [
        'parser' => env('PARLEY_PARSER'),
        'schema' => 'permissive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Moderation
    |--------------------------------------------------------------------------
    |
    | Which trove role may moderate: pin, lock, and soft-delete other people's
    | posts. Authors can always edit and delete their own.
    |
    | Stored as the enum's string value rather than a Role instance, because
    | `php artisan config:cache` serialises config to a PHP file and an enum
    | instance does not survive that round trip.
    |
    | "edit_window" is minutes an author may edit their own post; null means
    | forever.
    |
    */

    'moderation' => [
        'role' => 'moderator',
        'edit_window' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Set "enabled" to false to register no routes at all and drive parley
    | entirely through its services and Livewire components.
    |
    */

    'routes' => [
        'enabled' => true,
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    |
    | The Blade layout parley's own pages extend. Defaults to the shell that
    | marque/id provides.
    |
    */

    'layout' => 'id::layouts.app',
];
