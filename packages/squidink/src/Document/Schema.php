<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document;

use Marque\SquidInk\Document\Marks\Bold;
use Marque\SquidInk\Document\Marks\Code as CodeMark;
use Marque\SquidInk\Document\Marks\Colour;
use Marque\SquidInk\Document\Marks\Italic;
use Marque\SquidInk\Document\Marks\Link;
use Marque\SquidInk\Document\Marks\Size;
use Marque\SquidInk\Document\Marks\Strike;
use Marque\SquidInk\Document\Marks\Underline;
use Marque\SquidInk\Document\Nodes\BlockQuote;
use Marque\SquidInk\Document\Nodes\BulletList;
use Marque\SquidInk\Document\Nodes\CodeBlock;
use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Nodes\HardBreak;
use Marque\SquidInk\Document\Nodes\Heading;
use Marque\SquidInk\Document\Nodes\HorizontalRule;
use Marque\SquidInk\Document\Nodes\Image;
use Marque\SquidInk\Document\Nodes\ListItem;
use Marque\SquidInk\Document\Nodes\OrderedList;
use Marque\SquidInk\Document\Nodes\Paragraph;
use Marque\SquidInk\Document\Nodes\Shortcode;
use Marque\SquidInk\Document\Nodes\Text;
use Marque\SquidInk\Exceptions\SchemaViolation;

/**
 * Declares which nodes and marks a document may contain.
 *
 * This is the security model, not a style preference. A parser cannot produce a
 * node the schema does not declare, so unsupported or hostile input cannot
 * become unexpected output. That is what removes the whole sanitiser-gap class
 * of bug rather than mitigating it: there is no "did we strip every dangerous
 * tag" question, because only declared node types exist at all.
 *
 * Restricting what users may write is done by narrowing the schema — a comments
 * field might allow only paragraphs, text and links.
 */
final class Schema
{
    /** Every node type the package ships. */
    public const ALL_NODES = [
        'document' => Document::class,
        'paragraph' => Paragraph::class,
        'text' => Text::class,
        'heading' => Heading::class,
        'code_block' => CodeBlock::class,
        'block_quote' => BlockQuote::class,
        'bullet_list' => BulletList::class,
        'ordered_list' => OrderedList::class,
        'list_item' => ListItem::class,
        'image' => Image::class,
        'horizontal_rule' => HorizontalRule::class,
        'hard_break' => HardBreak::class,
        'shortcode' => Shortcode::class,
    ];

    /** Every mark type the package ships. */
    public const ALL_MARKS = [
        'bold' => Bold::class,
        'italic' => Italic::class,
        'underline' => Underline::class,
        'strike' => Strike::class,
        'code' => CodeMark::class,
        'link' => Link::class,
        'colour' => Colour::class,
        'size' => Size::class,
    ];

    /**
     * Nodes a document cannot be built without.
     */
    private const REQUIRED_NODES = ['document', 'paragraph', 'text'];

    /** Nodes that sit inside a paragraph or heading rather than beside them. */
    private const INLINE_NODES = ['text', 'hard_break', 'image'];

    /** Nodes whose children are inline: unwrapping a block in here must not add a paragraph. */
    private const INLINE_PARENTS = ['paragraph', 'heading'];

    /** @var list<string> */
    private array $nodes;

    /** @var list<string> */
    private array $marks;

    /**
     * @param  list<string>  $nodes  Empty means every node type.
     * @param  list<string>  $marks  Empty means every mark type.
     */
    public function __construct(array $nodes = [], array $marks = [])
    {
        $this->nodes = $nodes === []
            ? array_keys(self::ALL_NODES)
            : array_values(array_unique([...$nodes, ...self::REQUIRED_NODES]));

        $this->marks = $marks === []
            ? array_keys(self::ALL_MARKS)
            : array_values(array_unique($marks));
    }

    /**
     * Everything the package supports.
     */
    public static function permissive(): self
    {
        return new self;
    }

    /**
     * Text, emphasis and links only — no images, no headings, no blocks.
     * A sensible starting point for short-form fields like comments.
     */
    public static function minimal(): self
    {
        return new self(
            ['document', 'paragraph', 'text', 'hard_break'],
            ['bold', 'italic', 'strike', 'code', 'link'],
        );
    }

    public function allowsNode(string $type): bool
    {
        return in_array($type, $this->nodes, true);
    }

    public function allowsMark(string $type): bool
    {
        return in_array($type, $this->marks, true);
    }

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<string>
     */
    public function marks(): array
    {
        return $this->marks;
    }

    /**
     * Throws on the first node or mark this schema does not permit.
     *
     * Parsers should prefer filter(), which drops disallowed content instead —
     * validation is for tests and for callers who want to know rather than cope.
     *
     * @throws SchemaViolation
     */
    public function validate(Node $document): void
    {
        foreach ($document->walk() as $node) {
            if (! $this->allowsNode($node->type())) {
                throw SchemaViolation::node($node->type());
            }

            if (! $node instanceof Text) {
                continue;
            }

            foreach ($node->marks() as $mark) {
                if (! $this->allowsMark($mark->type())) {
                    throw SchemaViolation::mark($mark->type());
                }
            }
        }
    }

    public function permits(Node $document): bool
    {
        try {
            $this->validate($document);
        } catch (SchemaViolation) {
            return false;
        }

        return true;
    }

    /**
     * Strips anything this schema does not permit, in place.
     *
     * Disallowed nodes are removed but their content is kept and spliced into
     * the parent — narrowing a schema should degrade formatting, not delete the
     * text someone wrote. For most nodes the content is their children. A code
     * block and an image hold their words in a property instead, so they get a
     * fallback (see contentOf()), and a dropped hard break becomes a newline. An
     * unwrapped block's inline content is regrouped into paragraphs, so its words
     * stay apart from the next block's. Disallowed marks are dropped from the text they
     * annotate, leaving the text itself intact.
     *
     * This is what parsers use. It is the difference between "your post broke"
     * and "your post lost its colours".
     */
    public function filter(Node $node): Node
    {
        $this->filterMarks($node);

        $kept = [];

        foreach ($node->children() as $child) {
            $this->filter($child);

            if ($this->allowsNode($child->type())) {
                $kept[] = $child;

                continue;
            }

            // Unwrap: keep the content, lose the container. A block's inline
            // content is regrouped into paragraphs so it cannot run into the
            // words of the block beside it ("Heading wordsitem one").
            $content = $this->contentOf($child);

            if (! in_array($child->type(), self::INLINE_NODES, true)
                && ! in_array($node->type(), self::INLINE_PARENTS, true)) {
                $content = $this->paragraphed($content);
            }

            foreach ($content as $piece) {
                $kept[] = $piece;
            }
        }

        $node->replaceChildren($kept);

        return $node;
    }

    /**
     * What survives of a node this schema does not allow.
     *
     * Children, for anything that has them. A code block's text, an image's alt
     * and a quote's author live in properties, so plain unwrapping would delete
     * them: the code
     * becomes a paragraph of its lines (code-marked and broken by hard breaks
     * where this schema allows those), the image becomes its alt text, and the
     * author becomes an "Author:" paragraph ahead of the quote's content.
     *
     * @return list<Node>
     */
    private function contentOf(Node $node): array
    {
        if ($node instanceof CodeBlock) {
            return $node->code() === '' ? [] : [new Paragraph(children: $this->codeLines($node->code()))];
        }

        if ($node instanceof Image) {
            return in_array($node->alt(), [null, ''], true) ? [] : [new Text($node->alt())];
        }

        // Who said it is content, not formatting: the plain-text renderer keeps
        // it the same way, as "Author:" before the quote.
        if ($node instanceof BlockQuote && $node->author() !== null) {
            return [new Paragraph(children: [new Text($node->author().':')]), ...$node->children()];
        }

        // A line break separates words; dropping it outright would join them.
        if ($node instanceof HardBreak) {
            return [new Text("\n")];
        }

        return $node->children();
    }

    /**
     * Wraps each run of inline nodes in a paragraph, leaving blocks as they are.
     *
     * @param  list<Node>  $nodes
     * @return list<Node>
     */
    private function paragraphed(array $nodes): array
    {
        $result = [];
        $run = [];

        foreach ($nodes as $node) {
            if (in_array($node->type(), self::INLINE_NODES, true)) {
                $run[] = $node;

                continue;
            }

            if ($run !== []) {
                $result[] = new Paragraph(children: $run);
                $run = [];
            }

            $result[] = $node;
        }

        if ($run !== []) {
            $result[] = new Paragraph(children: $run);
        }

        return $result;
    }

    /**
     * @return list<Node>
     */
    private function codeLines(string $code): array
    {
        $marks = $this->allowsMark('code') ? [new CodeMark] : [];

        if (! $this->allowsNode('hard_break')) {
            return [new Text($code, $marks)];
        }

        $nodes = [];

        foreach (preg_split('/\R/', $code) as $i => $line) {
            if ($i > 0) {
                $nodes[] = new HardBreak;
            }

            if ($line !== '') {
                $nodes[] = new Text($line, $marks);
            }
        }

        return $nodes;
    }

    private function filterMarks(Node $node): void
    {
        if (! $node instanceof Text) {
            return;
        }

        foreach ($node->marks() as $mark) {
            if (! $this->allowsMark($mark->type())) {
                $node->removeMark($mark->type());
            }
        }
    }
}
