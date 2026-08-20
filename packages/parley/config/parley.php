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

        // Source-text length cap for a post body, enforced at the Livewire
        // component's validation layer. Not a squidink or database concern —
        // this is purely "how much can one message be", the same kind of
        // limit any UGC form needs.
        'max_length' => env('PARLEY_MAX_LENGTH', 20000),
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
    | "lock_blocks_edits" decides what locking a thread actually stops. false
    | (the default) means lock only stops NEW posts and replies — an author
    | can still edit or delete their own existing words, "locked" reads as
    | "no new discussion" rather than "frozen". true makes a locked thread's
    | content immutable to everyone but a moderator, which suits a deployment
    | that uses locking to archive/freeze a thread rather than just to stop
    | it growing. This is a site-owner policy call, not something Marque
    | should hardcode either way — see docs/why.md's "auth-agnostic" reasoning
    | for the same principle applied to a different axis.
    |
    | TEMPORARY: this is a plain config toggle because there is currently no
    | central settings/admin surface for a site owner to flip it without a
    | redeploy. See job #10554 — once that surface exists, this setting (and
    | most of this file) is a candidate to migrate onto it rather than stay
    | env()-and-redeploy-only.
    |
    */

    'moderation' => [
        'role' => 'moderator',
        'edit_window' => null,
        'lock_blocks_edits' => env('PARLEY_LOCK_BLOCKS_EDITS', false),
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
    | marque/ise provides.
    |
    */

    'layout' => 'ise::layouts.app',
];
