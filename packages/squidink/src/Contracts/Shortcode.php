<?php

declare(strict_types=1);

namespace Marque\SquidInk\Contracts;

use Marque\SquidInk\Document\Nodes\Shortcode as ShortcodeNode;

/**
 * Platform-aware content: a spoiler, a MediaInfo block, a torrent status pill.
 *
 * Shortcodes are where the platform adds value over generic formatting. A
 * consuming package registers one by name — trove might register `torrent`, a
 * site might register whatever it likes — and the parser turns matching input
 * into a Shortcode node rather than text.
 *
 * Rendering is per output format, so a spoiler can be a collapsible block in
 * HTML and its plain contents in a search index.
 */
interface Shortcode
{
    /**
     * The name written in source: `{spoiler}...{/spoiler}` is named "spoiler".
     * Lowercase, no delimiters.
     */
    public function name(): string;

    /**
     * Whether this shortcode wraps content. Paired shortcodes are written
     * `{name}...{/name}`; unpaired ones are `{name}` or `{name attr=x}`.
     */
    public function isPaired(): bool;

    /**
     * Render for one output format. Return null to decline, in which case the
     * renderer falls back to the node's children as ordinary content — so an
     * HTML-only shortcode still degrades sensibly in plain text.
     *
     * $renderChildren renders the node's children in the current format, so an
     * implementation can wrap already-rendered content without knowing how.
     *
     * @param  callable(): string  $renderChildren
     */
    public function render(ShortcodeNode $node, string $format, callable $renderChildren): ?string;
}
