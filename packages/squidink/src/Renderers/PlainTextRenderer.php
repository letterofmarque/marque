<?php

declare(strict_types=1);

namespace Marque\SquidInk\Renderers;

use Marque\SquidInk\Contracts\Renderer;
use Marque\SquidInk\Document\Node;
use Marque\SquidInk\Document\Nodes\BlockQuote;
use Marque\SquidInk\Document\Nodes\CodeBlock;
use Marque\SquidInk\Document\Nodes\Image;
use Marque\SquidInk\Document\Nodes\Shortcode;
use Marque\SquidInk\Document\Nodes\Text;
use Marque\SquidInk\Shortcodes\ShortcodeRegistry;

/**
 * Documents to plain text — API responses, search indexing, notification
 * excerpts, anywhere markup would be noise.
 *
 * Formatting is discarded rather than approximated. No asterisks for bold, no
 * underlines for headings: this output is meant to be read by a search index or
 * shown in a context that cannot render anything, and fake markup helps neither.
 */
final class PlainTextRenderer implements Renderer
{
    public function __construct(
        private ?ShortcodeRegistry $shortcodes = null,
    ) {}

    public function name(): string
    {
        return 'text';
    }

    public function render(Node $node): string
    {
        return rtrim($this->node($node), "\n");
    }

    private function node(Node $node): string
    {
        return match ($node->type()) {
            'document' => $this->blocks($node),
            'paragraph', 'heading' => $this->children($node)."\n\n",
            'block_quote' => $this->blockQuote($node),
            'bullet_list', 'ordered_list' => $this->children($node)."\n",
            'list_item' => trim($this->children($node))."\n",
            'code_block' => $this->codeBlock($node),
            'horizontal_rule' => "\n",
            'hard_break' => "\n",
            'image' => $this->image($node),
            'text' => $node instanceof Text ? $node->text() : '',
            'shortcode' => $this->shortcode($node),
            default => $this->children($node),
        };
    }

    private function blocks(Node $node): string
    {
        return $this->children($node);
    }

    private function children(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            $text .= $this->node($child);
        }

        return $text;
    }

    private function blockQuote(Node $node): string
    {
        $content = $this->children($node);

        // Who said it is content, not formatting, so it survives into plain text
        // where a search index can see it.
        if ($node instanceof BlockQuote && $node->author() !== null) {
            return $node->author().":\n".$content."\n";
        }

        return $content."\n\n";
    }

    private function codeBlock(Node $node): string
    {
        if (! $node instanceof CodeBlock) {
            return '';
        }

        // Verbatim, as everywhere else.
        return $node->code()."\n\n";
    }

    private function shortcode(Node $node): string
    {
        if (! $node instanceof Shortcode) {
            return '';
        }

        $rendered = $this->shortcodes?->render(
            $node,
            $this->name(),
            fn (): string => $this->children($node),
        );

        return $rendered ?? $this->children($node);
    }

    private function image(Node $node): string
    {
        if (! $node instanceof Image) {
            return '';
        }

        // Alt text is the only part of an image that means anything in plain
        // text; a bare URL is noise in a search index.
        return $node->alt() ?? '';
    }
}
