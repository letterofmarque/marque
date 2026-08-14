<?php

declare(strict_types=1);

namespace Marque\SquidInk\Parsers;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote as CmBlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading as CmHeading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem as CmListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code as CmCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image as CmImage;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link as CmLink;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Node\Block\Paragraph as CmParagraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text as CmText;
use League\CommonMark\Node\Node as CmNode;
use League\CommonMark\Parser\MarkdownParser as CommonMarkParser;
use Marque\SquidInk\Contracts\Parser;
use Marque\SquidInk\Document\Mark;
use Marque\SquidInk\Document\Marks\Bold;
use Marque\SquidInk\Document\Marks\Code as CodeMark;
use Marque\SquidInk\Document\Marks\Italic;
use Marque\SquidInk\Document\Marks\Link;
use Marque\SquidInk\Document\Marks\Strike;
use Marque\SquidInk\Document\Node;
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
use Marque\SquidInk\Document\Nodes\Text;
use Marque\SquidInk\Document\Schema;

/**
 * CommonMark input.
 *
 * league/commonmark does the lexing and block parsing — there is no reason to
 * reimplement a spec-compliant Markdown parser — but its AST is an
 * implementation detail, not our document model. Everything it produces is
 * mapped onto SquidInk nodes here.
 *
 * That mapping is the point rather than overhead. It is what keeps Markdown a
 * peer of BBCode instead of the privileged format everything else converts
 * into, and it is where raw HTML gets dropped.
 */
final class MarkdownParser implements Parser
{
    private CommonMarkParser $engine;

    public function __construct()
    {
        $environment = new Environment([
            // Raw HTML in the source is not parsed into HTML nodes at all.
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new StrikethroughExtension);

        $this->engine = new CommonMarkParser($environment);
    }

    public function name(): string
    {
        return 'markdown';
    }

    public function parse(string $source, Schema $schema): Document
    {
        $parsed = $this->engine->parse($source);

        $document = new Document;

        foreach ($parsed->children() as $child) {
            foreach ($this->convert($child, []) as $node) {
                $document->append($node);
            }
        }

        $schema->filter($document);

        return $document;
    }

    /**
     * Map one CommonMark node to zero or more SquidInk nodes.
     *
     * Returns a list because some CommonMark nodes (emphasis, links) are
     * containers that flatten into several marked Text nodes, and some
     * (HtmlBlock, HtmlInline) produce nothing at all.
     *
     * @param  list<Mark>  $marks  Inline marks accumulated from ancestors.
     * @return list<Node>
     */
    private function convert(CmNode $node, array $marks): array
    {
        return match (true) {
            // A paragraph inside a tight list item is structural only —
            // CommonMark uses it for parsing, but "- a" should render as
            // <li>a</li>, not <li><p>a</p></li>. Unwrap it.
            $node instanceof CmParagraph => $this->isTight($node)
                ? $this->inline($node, $marks)
                : [$this->block(new Paragraph, $node, $marks)],
            $node instanceof CmHeading => [$this->block(new Heading($node->getLevel()), $node, $marks)],
            $node instanceof CmBlockQuote => [$this->block(new BlockQuote, $node, $marks)],
            $node instanceof CmListItem => [$this->block(new ListItem, $node, $marks)],
            $node instanceof ListBlock => [$this->list($node, $marks)],

            $node instanceof FencedCode => [new CodeBlock($this->code($node), $this->language($node))],
            $node instanceof IndentedCode => [new CodeBlock($this->code($node))],
            $node instanceof ThematicBreak => [new HorizontalRule],

            // CommonMark's Newline covers both kinds of line ending, and they
            // mean different things: a hard break (two trailing spaces, or a
            // backslash) is a break the author asked for, while a soft break is
            // just where the source line happened to wrap and renders as
            // whitespace. Mapping both to HardBreak would make Markdown honour
            // wrapping the way BBCode does, which CommonMark explicitly does not.
            $node instanceof Newline => $node->getType() === Newline::HARDBREAK
                ? [new HardBreak]
                : [new Text("\n", $marks)],

            $node instanceof CmText => [new Text($node->getLiteral(), $marks)],
            $node instanceof CmCode => [new Text($node->getLiteral(), [...$marks, new CodeMark])],

            $node instanceof Strong => $this->inline($node, [...$marks, new Bold]),
            $node instanceof Emphasis => $this->inline($node, [...$marks, new Italic]),
            $node instanceof Strikethrough => $this->inline($node, [...$marks, new Strike]),

            $node instanceof CmLink => $this->inline(
                $node,
                [...$marks, new Link($node->getUrl(), $node->getTitle())],
            ),

            $node instanceof CmImage => [new Image(
                $node->getUrl(),
                $this->textOf($node),
                $node->getTitle(),
            )],

            // Raw HTML (HtmlBlock, HtmlInline) and anything else unrecognised
            // produces nothing. This is where "html_input => strip" is enforced
            // structurally rather than trusted.
            default => [],
        };
    }

    /**
     * @param  list<Mark>  $marks
     */
    private function block(Node $target, CmNode $source, array $marks): Node
    {
        foreach ($source->children() as $child) {
            $target->appendAll($this->convert($child, $marks));
        }

        return $target;
    }

    /**
     * @param  list<Mark>  $marks
     */
    private function list(ListBlock $node, array $marks): Node
    {
        $data = $node->getListData();

        $list = $data->type === ListBlock::TYPE_ORDERED
            ? new OrderedList($data->start ?? 1)
            : new BulletList;

        return $this->block($list, $node, $marks);
    }

    /**
     * Flatten an inline container into its marked children.
     *
     * @param  list<Mark>  $marks
     * @return list<Node>
     */
    private function inline(CmNode $node, array $marks): array
    {
        $nodes = [];

        foreach ($node->children() as $child) {
            foreach ($this->convert($child, $marks) as $converted) {
                $nodes[] = $converted;
            }
        }

        return $nodes;
    }

    /**
     * Whether a paragraph is the direct child of a tight list item, in which
     * case CommonMark treats it as a parsing artefact rather than a real block.
     */
    private function isTight(CmParagraph $node): bool
    {
        $parent = $node->parent();

        if (! $parent instanceof CmListItem) {
            return false;
        }

        $list = $parent->parent();

        return $list instanceof ListBlock && $list->isTight();
    }

    /**
     * A code block's content, without the newline that precedes its closing
     * delimiter.
     *
     * CommonMark's AST includes that newline because it is part of the block's
     * literal text, but it is syntax rather than content — the author wrote it to
     * put ``` on its own line. Dropping it here is what lets the same code block
     * written in Markdown and in BBCode be byte-identical, which is the whole
     * point of CodeBlock. Only one is dropped, so deliberate trailing blank lines
     * survive.
     */
    private function code(FencedCode|IndentedCode $node): string
    {
        $literal = $node->getLiteral();

        return str_ends_with($literal, "\n") ? substr($literal, 0, -1) : $literal;
    }

    private function language(FencedCode $node): ?string
    {
        $info = trim($node->getInfo() ?? '');

        if ($info === '') {
            return null;
        }

        // "php linenums" — the language is the first word.
        $language = explode(' ', $info)[0];

        return preg_match('/^[a-z0-9_+-]+$/i', $language) === 1 ? $language : null;
    }

    /**
     * Concatenated literal text of a node's descendants — used for image alt
     * text, which is plain text rather than a subtree.
     */
    private function textOf(CmNode $node): ?string
    {
        $text = '';

        foreach ($node->children() as $child) {
            if ($child instanceof CmText) {
                $text .= $child->getLiteral();

                continue;
            }

            $text .= $this->textOf($child) ?? '';
        }

        return $text === '' ? null : $text;
    }
}
