<?php

declare(strict_types=1);

namespace Marque\SquidInk\Shortcodes;

use Marque\SquidInk\Contracts\Shortcode;
use Marque\SquidInk\Document\Nodes\Shortcode as ShortcodeNode;

/**
 * Hidden content behind a click.
 *
 *     {spoiler}the butler did it{/spoiler}
 *     {spoiler title="Ending"}...{/spoiler}
 *
 * Uses <details>/<summary>, so it works without JavaScript.
 */
final class SpoilerShortcode implements Shortcode
{
    public function name(): string
    {
        return 'spoiler';
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
                '<details class="squidink-spoiler"><summary>%s</summary>%s</details>',
                $this->escape($this->title($node)),
                $content,
            ),

            // In plain text a spoiler is just its content — a search index
            // should still find what is inside one.
            'text' => $content,

            default => null,
        };
    }

    private function title(ShortcodeNode $node): string
    {
        $title = $node->attribute('title');

        return is_string($title) && trim($title) !== '' ? $title : 'Spoiler';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
