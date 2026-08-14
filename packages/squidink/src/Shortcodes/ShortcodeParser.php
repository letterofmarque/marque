<?php

declare(strict_types=1);

namespace Marque\SquidInk\Shortcodes;

use Marque\SquidInk\Document\Node;
use Marque\SquidInk\Document\Nodes\Shortcode as ShortcodeNode;
use Marque\SquidInk\Document\Nodes\Text;

/**
 * Finds shortcodes inside already-parsed text and splits them into nodes.
 *
 * Runs as a pass over a parsed document rather than inside any one parser, so
 * every input syntax gets shortcodes without each implementing them.
 *
 * Delimiters are braces — `{spoiler}...{/spoiler}` — deliberately not square
 * brackets, which would be ambiguous with BBCode input.
 *
 * Unregistered names are left as literal text. A shortcode this site does not
 * know about shows its source rather than disappearing.
 */
final class ShortcodeParser
{
    /**
     * name, optional attributes, closing brace. Names are conservative on
     * purpose: letters, digits, underscore and hyphen only.
     */
    private const PATTERN = '/\{(\/?)([a-z][a-z0-9_-]*)((?:\s+[^{}]*)?)\}/i';

    public function __construct(
        private ShortcodeRegistry $registry,
    ) {}

    /**
     * Walk a tree, replacing shortcode syntax inside Text nodes with nodes.
     */
    public function process(Node $node): Node
    {
        if (! $node->hasChildren()) {
            return $node;
        }

        $children = [];

        foreach ($node->children() as $child) {
            if ($child instanceof Text) {
                foreach ($this->split($child) as $piece) {
                    $children[] = $piece;
                }

                continue;
            }

            $children[] = $this->process($child);
        }

        $node->replaceChildren($this->unwrapBlocks($this->pair($children)));

        return $node;
    }

    /**
     * Lift a block-level shortcode out of the paragraph wrapping it.
     *
     * A shortcode written on its own line parses as paragraph content, but
     * something like <details> is flow content — a browser silently closes the
     * <p> before it and the nesting breaks. Where a paragraph contains nothing
     * but one shortcode, the paragraph is dropped.
     *
     * @param  list<Node>  $children
     * @return list<Node>
     */
    private function unwrapBlocks(array $children): array
    {
        $result = [];

        foreach ($children as $child) {
            if ($child->type() !== 'paragraph') {
                $result[] = $child;

                continue;
            }

            $inner = array_values(array_filter(
                $child->children(),
                static fn (Node $n): bool => ! ($n instanceof Text && trim($n->text()) === '')
                    && $n->type() !== 'hard_break',
            ));

            if (count($inner) === 1 && $inner[0] instanceof ShortcodeNode) {
                $result[] = $inner[0];

                continue;
            }

            $result[] = $child;
        }

        return $result;
    }

    /**
     * Split one Text node into text and shortcode-marker nodes.
     *
     * Marks carry across the split: `**{spoiler}hidden{/spoiler}**` keeps its
     * bold on the surrounding text.
     *
     * @return list<Node>
     */
    private function split(Text $text): array
    {
        $source = $text->text();

        if (! str_contains($source, '{')) {
            return [$text];
        }

        preg_match_all(self::PATTERN, $source, $matches, PREG_OFFSET_CAPTURE);

        if ($matches[0] === []) {
            return [$text];
        }

        $nodes = [];
        $cursor = 0;

        foreach ($matches[0] as $index => [$literal, $offset]) {
            $name = $matches[2][$index][0];
            $isClosing = $matches[1][$index][0] === '/';

            if (! $this->registry->has($name)) {
                continue;
            }

            if ($offset > $cursor) {
                $nodes[] = new Text(substr($source, $cursor, $offset - $cursor), $text->marks());
            }

            $node = new ShortcodeNode(
                strtolower($name),
                $this->attributes($matches[3][$index][0]),
            );

            // Marker for pair(); closing tags carry no attributes.
            $node->setAttribute('__closing', $isClosing);

            $nodes[] = $node;
            $cursor = $offset + strlen($literal);
        }

        if ($nodes === []) {
            return [$text];
        }

        if ($cursor < strlen($source)) {
            $nodes[] = new Text(substr($source, $cursor), $text->marks());
        }

        return $nodes;
    }

    /**
     * Turn a flat run of open/close markers into nested nodes.
     *
     * An unclosed opener keeps its content as siblings rather than swallowing
     * the rest of the document; a stray closer becomes literal text. Malformed
     * input degrades, never throws.
     *
     * @param  list<Node>  $nodes
     * @return list<Node>
     */
    private function pair(array $nodes): array
    {
        $result = [];
        /** @var list<array{node: ShortcodeNode, buffer: list<Node>}> $stack */
        $stack = [];

        foreach ($nodes as $node) {
            if (! $node instanceof ShortcodeNode) {
                $this->push($stack, $result, $node);

                continue;
            }

            $closing = (bool) $node->attribute('__closing', false);
            $node->setAttribute('__closing', null);

            if (! $closing) {
                if ($this->registry->isPaired($node->name())) {
                    $stack[] = ['node' => $node, 'buffer' => []];
                } else {
                    $this->push($stack, $result, $node);
                }

                continue;
            }

            $open = $this->unwind($stack, $node->name());

            if ($open === null) {
                // Stray closer — render it as the text it was.
                $this->push($stack, $result, new Text('{/'.$node->name().'}'));

                continue;
            }

            $open['node']->appendAll($open['buffer']);
            $this->push($stack, $result, $open['node']);
        }

        // Anything still open never closed: keep its opener as text, and its
        // content as ordinary siblings.
        foreach ($stack as $unclosed) {
            $result[] = new Text('{'.$unclosed['node']->name().'}');

            foreach ($unclosed['buffer'] as $orphan) {
                $result[] = $orphan;
            }
        }

        return $result;
    }

    /**
     * @param  list<array{node: ShortcodeNode, buffer: list<Node>}>  $stack
     * @param  list<Node>  $result
     */
    private function push(array &$stack, array &$result, Node $node): void
    {
        if ($stack === []) {
            $result[] = $node;

            return;
        }

        $stack[array_key_last($stack)]['buffer'][] = $node;
    }

    /**
     * Pop to the matching opener, folding any unclosed inner shortcodes into
     * their parent's buffer as literal text plus content.
     *
     * @param  list<array{node: ShortcodeNode, buffer: list<Node>}>  $stack
     * @return array{node: ShortcodeNode, buffer: list<Node>}|null
     */
    private function unwind(array &$stack, string $name): ?array
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['node']->name() !== $name) {
                continue;
            }

            $matched = $stack[$i];

            // Inner shortcodes left open are not shortcodes after all.
            for ($j = count($stack) - 1; $j > $i; $j--) {
                $inner = array_pop($stack);
                $matched['buffer'][] = new Text('{'.$inner['node']->name().'}');

                foreach ($inner['buffer'] as $orphan) {
                    $matched['buffer'][] = $orphan;
                }
            }

            array_pop($stack);

            return $matched;
        }

        return null;
    }

    /**
     * Parse `key=value key2="quoted value"` into attributes.
     *
     * @return array<string, scalar|null>
     */
    private function attributes(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        preg_match_all(
            '/([a-z][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))/i',
            $raw,
            $matches,
            PREG_SET_ORDER,
        );

        $attributes = [];

        foreach ($matches as $match) {
            $value = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));
            $attributes[strtolower($match[1])] = $value;
        }

        return $attributes;
    }
}
