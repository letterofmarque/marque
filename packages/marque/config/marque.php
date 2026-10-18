<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Install
    |--------------------------------------------------------------------------
    |
    | What marque:install reads before the packages it installs, and their
    | config, exist.
    |
    | redis_durability: the installer refuses to build a private tracker on a
    | Redis that can forget its peers (no AOF, or an eviction policy). Set
    | BLOODHOUND_REDIS_DURABILITY=attested if your provider makes Redis
    | durable another way: that covers persistence, and the eviction policy is
    | still checked. It is the same variable bloodhound reads once installed,
    | and bloodhound's setting wins when it is present. Read here, through
    | config, so it holds with config cached.
    |
    */

    'install' => [
        'redis_durability' => env('BLOODHOUND_REDIS_DURABILITY', 'required'),
    ],

];
