<?php

declare(strict_types=1);

namespace Marque\SquidInk\Renderers;

use Marque\SquidInk\Contracts\Renderer;
use Marque\SquidInk\Document\Marks\Colour;
use Marque\SquidInk\Document\Marks\Link;
use Marque\SquidInk\Document\Marks\Size;
use Marque\SquidInk\Document\Node;
use Marque\SquidInk\Document\Nodes\BlockQuote;
use Marque\SquidInk\Document\Nodes\CodeBlock;
use Marque\SquidInk\Document\Nodes\Heading;
use Marque\SquidInk\Document\Nodes\Image;
use Marque\SquidInk\Document\Nodes\OrderedList;
use Marque\SquidInk\Document\Nodes\Shortcode;
use Marque\SquidInk\Document\Nodes\Text;
use Marque\SquidInk\Shortcodes\ShortcodeRegistry;

/**
 * Documents to HTML.
 *
 * There is no sanitising step here and that is deliberate: only nodes the
 * schema permitted can reach a renderer, and hrefs and colours were validated
 * when their marks were constructed. What remains is escaping — text content is
 * still arbitrary user input — and emitting a fixed set of tags.
 *
 * Every tag emitted is a literal in this file. No user input reaches a tag name
 * or an attribute name, only escaped attribute values.
 */
final class HtmlRenderer implements Renderer
{
    /**
     * The order marks nest in, outermost first.
     *
     * Marks are a set, not a sequence — but HTML nesting is ordered, so the
     * renderer has to choose. Choosing by a fixed table rather than by the order
     * a parser happened to add them is what stops the input syntax leaking into
     * the output: `***both***` and `[b][i]both[/i][/b]` are the same document and
     * must render to the same string.
     *
     * Link and colour sit outside the emphasis tags so that a styled link is one
     * anchor containing formatted text, rather than formatting containing several
     * anchors.
     */
    private const MARK_ORDER = [
        'link',
        'colour',
        'size',
        'bold',
        'italic',
        'underline',
        'strike',
        'code',
    ];

    public function __construct(
        private ?ShortcodeRegistry $shortcodes = null,
    ) {}

    public function name(): string
    {
        return 'html';
    }

    public function render(Node $node): string
    {
        return match ($node->type()) {
            'document' => $this->children($node),
            'paragraph' => $this->wrap('p', $this->children($node)),
            'heading' => $this->heading($node),
            'block_quote' => $this->blockQuote($node),
            'bullet_list' => $this->wrap('ul', $this->children($node)),
            'ordered_list' => $this->orderedList($node),
            'list_item' => $this->wrap('li', $this->children($node)),
            'code_block' => $this->codeBlock($node),
            'horizontal_rule' => '<hr>',
            'hard_break' => '<br>',
            'image' => $this->image($node),
            'text' => $this->text($node),
            'shortcode' => $this->shortcode($node),
            default => $this->children($node),
        };
    }

    private function children(Node $node): string
    {
        $html = '';

        foreach ($node->children() as $child) {
            $html .= $this->render($child);
        }

        return $html;
    }

    private function wrap(string $tag, string $content): string
    {
        return sprintf('<%s>%s</%s>', $tag, $content, $tag);
    }

    private function heading(Node $node): string
    {
        $level = $node instanceof Heading ? $node->level() : 1;

        return $this->wrap('h'.$level, $this->children($node));
    }

    private function blockQuote(Node $node): string
    {
        $content = $this->children($node);

        // An attributed quote gets a <cite>; browsers ignore <blockquote cite>
        // for display, and the name is worth showing.
        if ($node instanceof BlockQuote && $node->author() !== null) {
            $content = $this->wrap('cite', $this->escape($node->author())).$content;
        }

        return $this->wrap('blockquote', $content);
    }

    private function orderedList(Node $node): string
    {
        $start = $node instanceof OrderedList ? $node->start() : 1;

        $open = $start === 1 ? '<ol>' : sprintf('<ol start="%d">', $start);

        return $open.$this->children($node).'</ol>';
    }

    private function codeBlock(Node $node): string
    {
        if (! $node instanceof CodeBlock) {
            return '';
        }

        $language = $node->language();

        $open = $language === null
            ? '<pre><code>'
            : sprintf('<pre><code class="language-%s">', $this->escape($language));

        // Escaped, never trimmed or reindented — NFO art depends on every byte.
        return $open.$this->escape($node->code()).'</code></pre>';
    }

    private function image(Node $node): string
    {
        if (! $node instanceof Image) {
            return '';
        }

        // An image src is a reference the resolver may have rewritten; treat it
        // with the same scheme rules as a link so a javascript: src cannot land.
        $src = Link::sanitiseHref($node->src());

        if ($src === '') {
            return $node->alt() === null ? '' : $this->escape($node->alt());
        }

        $html = sprintf('<img src="%s"', $this->escape($src));

        if ($node->alt() !== null) {
            $html .= sprintf(' alt="%s"', $this->escape($node->alt()));
        }

        if ($node->title() !== null) {
            $html .= sprintf(' title="%s"', $this->escape($node->title()));
        }

        return $html.'>';
    }

    private function text(Node $node): string
    {
        if (! $node instanceof Text) {
            return '';
        }

        $html = $this->escape($node->text());

        // Innermost first, so the resulting nesting is stable and predictable.
        foreach ($this->ordered($node->marks()) as $mark) {
            $html = match ($mark->type()) {
                'bold' => $this->wrap('strong', $html),
                'italic' => $this->wrap('em', $html),
                'underline' => $this->wrap('u', $html),
                'strike' => $this->wrap('s', $html),
                'code' => $this->wrap('code', $html),
                'link' => $this->link($mark, $html),
                'colour' => $this->colour($mark, $html),
                'size' => $this->size($mark, $html),
                default => $html,
            };
        }

        return $html;
    }

    /**
     * Marks sorted innermost first, ready to wrap around text in sequence.
     *
     * Anything not in MARK_ORDER sorts innermost, in its existing relative
     * order — a mark a consumer added is applied closest to the text rather than
     * silently dropped or reordered against its peers.
     *
     * @param  list<\Marque\SquidInk\Document\Mark>  $marks
     * @return list<\Marque\SquidInk\Document\Mark>
     */
    private function ordered(array $marks): array
    {
        $rank = static function (string $type): int {
            $position = array_search($type, self::MARK_ORDER, true);

            return $position === false ? count(self::MARK_ORDER) : $position;
        };

        // Stable sort, descending: the last mark applied is the innermost tag.
        $indexed = [];

        foreach ($marks as $index => $mark) {
            $indexed[] = [$rank($mark->type()), $index, $mark];
        }

        usort($indexed, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);

        return array_map(static fn (array $row) => $row[2], $indexed);
    }

    private function link(mixed $mark, string $content): string
    {
        if (! $mark instanceof Link) {
            return $content;
        }

        // Sanitisation happened in the constructor; an empty href means the URL
        // was refused, so render the text without a link rather than dropping it.
        if ($mark->href() === '') {
            return $content;
        }

        $html = sprintf(
            '<a href="%s" rel="nofollow noopener ugc"',
            $this->escape($mark->href()),
        );

        if ($mark->title() !== null) {
            $html .= sprintf(' title="%s"', $this->escape($mark->title()));
        }

        return $html.'>'.$content.'</a>';
    }

    private function colour(mixed $mark, string $content): string
    {
        if (! $mark instanceof Colour || $mark->colour() === '') {
            return $content;
        }

        // The value is a hex code or a name from a fixed list — validated in the
        // constructor, so it cannot break out of the style attribute.
        return sprintf('<span style="color:%s">%s</span>', $mark->colour(), $content);
    }

    private function size(mixed $mark, string $content): string
    {
        if (! $mark instanceof Size || $mark->length() === '') {
            return $content;
        }

        // The length comes from a fixed scale keyed by a validated step, so no
        // user input reaches the style attribute.
        return sprintf('<span style="font-size:%s">%s</span>', $mark->length(), $content);
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

        // Unregistered, or registered but declining this format: render the
        // children as ordinary content rather than erroring.
        return $rendered ?? $this->children($node);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
