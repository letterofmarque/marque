<?php

declare(strict_types=1);

namespace Marque\SquidInk\Shortcodes;

use Marque\SquidInk\Contracts\Shortcode;
use Marque\SquidInk\Document\Nodes\Shortcode as ShortcodeNode;

/**
 * A collapsed MediaInfo dump.
 *
 *     {mediainfo}
 *     General
 *     Format  : Matroska
 *     ...
 *     {/mediainfo}
 *
 * The tracker-specific shortcode that justifies having shortcodes at all.
 * MediaInfo output is long, monospaced, and nobody wants it expanded by default
 * — but it must survive byte-for-byte, which is why the content is expected to
 * be a code block.
 */
final class MediaInfoShortcode implements Shortcode
{
    public function name(): string
    {
        return 'mediainfo';
    }

    public function isPaired(): bool
    {
        return true;
    }

    public function render(ShortcodeNode $node, string $format, callable $renderChildren): ?string
    {
        $content = $renderChildren();

        return match ($format) {
            'html' => sprintf(
                '<details class="squidink-mediainfo"><summary>MediaInfo</summary>'
                .'<pre class="squidink-mediainfo-body">%s</pre></details>',
                $content,
            ),

            // Kept out of plain text: a MediaInfo dump in a search index is
            // noise that drowns the actual description.
            'text' => '',

            default => null,
        };
    }
}
