<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Staff action retention
    |--------------------------------------------------------------------------
    |
    | Days to keep staff actions for. Null keeps them for good, which is the
    | default: an audit log is most useful when it reaches back to the thing
    | you're asking about. Set it and schedule bosun:prune to trim older ones.
    |
    */

    'retention_days' => env('BOSUN_RETENTION_DAYS'),

    /*
    |--------------------------------------------------------------------------
    | Staff presence
    |--------------------------------------------------------------------------
    |
    | When each staff member was last on the site, for the Staff screen. Only
    | staff (staff_role and above) are stamped, and at most once per
    | interval_minutes. Members are never tracked.
    |
    */

    'presence' => [
        'enabled' => env('BOSUN_PRESENCE', true),
        'interval_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Who counts as staff
    |--------------------------------------------------------------------------
    |
    | The lowest trove role the Staff screen lists and presence stamps.
    |
    */

    'staff_role' => 'moderator',

];
