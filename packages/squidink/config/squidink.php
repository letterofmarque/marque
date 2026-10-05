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
    | Supported: "markdown", "bbcode", or the name() of a parser you register
    |
    */

    'default_parser' => env('SQUIDINK_PARSER', 'markdown'),

    /*
    |--------------------------------------------------------------------------
    | Parsers
    |--------------------------------------------------------------------------
    |
    | Registered input syntaxes: a list of classes implementing
    | Marque\SquidInk\Contracts\Parser. Each registers under its own name().
    | Add your own here.
    |
    */

    'parsers' => [
        // Leave empty for the shipped defaults (markdown, bbcode). Naming any
        // parser here replaces the defaults entirely, so include the ones you
        // still want:
        //
        // 'markdown' => \Marque\SquidInk\Parsers\MarkdownParser::class,
        // 'bbcode'   => \Marque\SquidInk\Parsers\BBCodeParser::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Renderers
    |--------------------------------------------------------------------------
    |
    | Registered output formats: a list of classes implementing
    | Marque\SquidInk\Contracts\Renderer. Each registers under its own name().
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
    | here, so unsupported input can never become unexpected output. document,
    | paragraph and text are always allowed. Shortcodes are filtered too, so
    | leave out "shortcode" to turn them all off.
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
    | Planned, not built: nothing reads these keys yet, and every read renders
    | afresh (#10811). The intent is that rendered output is cached and
    | invalidated on edit, because text is read far more often than it is
    | written.
    |
    */

    'cache' => [
        // check-docs: ignore — not yet built, read by nothing until #10811
        'enabled' => env('SQUIDINK_CACHE', true),
        // check-docs: ignore — not yet built, read by nothing until #10811
        'store' => env('SQUIDINK_CACHE_STORE'),
        // check-docs: ignore — not yet built, read by nothing until #10811
        'ttl' => 60 * 60 * 24 * 7,
        // check-docs: ignore — not yet built, read by nothing until #10811
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
    | Planned, not built: nothing reads this key yet, and every reference renders
    | as-is. The intended resolver is a marque/stow package that fetches remote
    | images and stores them locally, preventing both link rot and leaking your
    | users' IP addresses to third-party image hosts (#10814).
    |
    */

    // check-docs: ignore — not yet built, read by nothing until #10814
    'image_resolver' => null,
];
