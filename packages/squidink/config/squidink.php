<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Parser
    |--------------------------------------------------------------------------
    |
    | The input syntax used when a record does not declare its own. Every piece
    | of stored text records the parser it was written with, so changing this
    | affects new content only — existing content keeps rendering correctly.
    |
    | Supported: "markdown", "bbcode", "plain"
    |
    */

    'default_parser' => env('SQUIDINK_PARSER', 'markdown'),

    /*
    |--------------------------------------------------------------------------
    | Parsers
    |--------------------------------------------------------------------------
    |
    | Registered input syntaxes. Each maps a name to a class implementing
    | Marque\SquidInk\Contracts\Parser. Add your own here.
    |
    */

    'parsers' => [
        // Leave empty for the shipped defaults (markdown). Naming any parser
        // here replaces the defaults entirely, so include the ones you still
        // want:
        //
        // 'markdown' => \Marque\SquidInk\Parsers\MarkdownParser::class,
        // 'bbcode'   => \App\Text\MyBBCodeParser::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Renderers
    |--------------------------------------------------------------------------
    |
    | Registered output formats. Each maps a name to a class implementing
    | Marque\SquidInk\Contracts\Renderer.
    |
    */

    'renderers' => [
        // Leave empty for the shipped defaults (html, text). As with parsers,
        // naming any replaces the defaults entirely.
        //
        // 'html' => \Marque\SquidInk\Renderers\HtmlRenderer::class,
        // 'text' => \Marque\SquidInk\Renderers\PlainTextRenderer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    |
    | Which nodes and marks documents may contain. This is the security model,
    | not a style preference: a parser cannot produce a node that is not listed
    | here, so unsupported input can never become unexpected output.
    |
    | Trimming this list is how you restrict what users can write. An empty
    | array means "everything the schema knows about".
    |
    */

    'schema' => [
        'nodes' => [],
        'marks' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shortcodes
    |--------------------------------------------------------------------------
    |
    | Platform-aware content registered by name. Consuming packages add their
    | own — trove might register a torrent status pill, for instance.
    |
    | Unregistered shortcodes render as literal text rather than erroring, so
    | content written for a site with more shortcodes still reads sensibly.
    |
    */

    'shortcodes' => [
        // Leave empty for the shipped defaults (spoiler, mediainfo). Naming any
        // here replaces the defaults entirely, so include the ones you want:
        //
        // \Marque\SquidInk\Shortcodes\SpoilerShortcode::class,
        // \Marque\SquidInk\Shortcodes\MediaInfoShortcode::class,
        // \App\Text\TorrentStatusShortcode::class,
        //
        // Written with braces rather than square brackets — {spoiler}...
        // {/spoiler} — so they are unambiguous alongside BBCode input.
    ],

    /*
    |--------------------------------------------------------------------------
    | Rendered Output Cache
    |--------------------------------------------------------------------------
    |
    | Text is read far more often than it is written, so rendered output is
    | cached and invalidated on edit. Set "enabled" to false to render on every
    | read (useful while developing a parser or renderer).
    |
    */

    'cache' => [
        'enabled' => env('SQUIDINK_CACHE', true),
        'store' => env('SQUIDINK_CACHE_STORE'),
        'ttl' => 60 * 60 * 24 * 7,
        'prefix' => 'squidink',
    ],

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    |
    | SquidInk does not resolve image references itself — it emits an image node
    | holding whatever reference the author wrote, and a resolver decides what
    | that means.
    |
    | Installing marque/stow registers a resolver that fetches remote images and
    | stores them locally, which prevents both link rot and leaking your users'
    | IP addresses to third-party image hosts. With no resolver registered, the
    | reference renders as-is.
    |
    */

    'image_resolver' => null,
];
