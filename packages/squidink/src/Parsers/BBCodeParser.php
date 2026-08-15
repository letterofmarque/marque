<?php

declare(strict_types=1);

namespace Marque\SquidInk\Parsers;

use Marque\SquidInk\Contracts\DescribesSyntax;
use Marque\SquidInk\Contracts\Parser;
use Marque\SquidInk\Document\Mark;
use Marque\SquidInk\Document\Marks\Bold;
use Marque\SquidInk\Document\Marks\Code as CodeMark;
use Marque\SquidInk\Document\Marks\Colour;
use Marque\SquidInk\Document\Marks\Italic;
use Marque\SquidInk\Document\Marks\Link;
use Marque\SquidInk\Document\Marks\Size;
use Marque\SquidInk\Document\Marks\Strike;
use Marque\SquidInk\Document\Marks\Underline;
use Marque\SquidInk\Document\Node;
use Marque\SquidInk\Document\Nodes\BlockQuote;
use Marque\SquidInk\Document\Nodes\BulletList;
use Marque\SquidInk\Document\Nodes\CodeBlock;
use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Nodes\HardBreak;
use Marque\SquidInk\Document\Nodes\HorizontalRule;
use Marque\SquidInk\Document\Nodes\Image;
use Marque\SquidInk\Document\Nodes\ListItem;
use Marque\SquidInk\Document\Nodes\OrderedList;
use Marque\SquidInk\Document\Nodes\Paragraph;
use Marque\SquidInk\Document\Nodes\Text;
use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Editor\Insertion;
use Marque\SquidInk\Parsers\BBCode\Lexer;
use Marque\SquidInk\Parsers\BBCode\Tag;
use Marque\SquidInk\Parsers\BBCode\Token;

/**
 * BBCode input.
 *
 * Hand-written against a closed tag table rather than built on a general-purpose
 * BBCode library, because the fixed vocabulary is the security model: a tag with
 * no entry in TAGS is not a tag, it is text. See BBCode\Tag.
 *
 * This is the second parser, and writing it is what validated the document
 * model (Spec #80: "the abstraction should be discovered by writing the second
 * implementation"). Two things it needed that Markdown never exercised:
 *
 *   - a Size mark, since [size] has no Markdown equivalent
 *   - an author on BlockQuote, since [quote=someone] carries attribution
 *
 * Everything else mapped onto the existing nodes and marks unchanged, which is
 * the result the IR was hoping for.
 *
 * BBCode is not one format but a family of dialects. This parses the common core
 * that TBDev, XBTiT and the Gazelle forks agree on; dialect-specific tags belong
 * in booty's converters, not here.
 */
final class BBCodeParser implements DescribesSyntax, Parser
{
    /**
     * The vocabulary. Anything absent renders as the literal text typed.
     */
    private const TAGS = [
        // Inline: emit a mark on the text they contain.
        'b' => Tag::INLINE,
        'i' => Tag::INLINE,
        'u' => Tag::INLINE,
        's' => Tag::INLINE,
        'url' => Tag::INLINE,
        'color' => Tag::INLINE,
        'colour' => Tag::INLINE,
        'size' => Tag::INLINE,

        // Block: emit structural nodes.
        'quote' => Tag::BLOCK,
        'list' => Tag::BLOCK,
        'ol' => Tag::BLOCK,
        'ul' => Tag::BLOCK,

        // Verbatim: content is never parsed.
        'code' => Tag::VERBATIM,
        'pre' => Tag::VERBATIM,

        // Leaf: self-contained, or content that is purely an argument.
        'img' => Tag::LEAF,
        'hr' => Tag::LEAF,
        '*' => Tag::LEAF,
    ];

    private Lexer $lexer;

    /** @var array<string, Tag> */
    private array $tags;

    public function __construct()
    {
        $this->tags = [];

        foreach (self::TAGS as $name => $kind) {
            $this->tags[$name] = new Tag($name, $kind);
        }

        $this->lexer = new Lexer($this->tags);
    }

    public function name(): string
    {
        return 'bbcode';
    }

    /**
     * The toolbar vocabulary, derived from the tag table above rather than
     * restated: a tag this parser cannot parse must not get a button.
     */
    private const INSERTIONS = [
        'bold' => ['B', 'b'],
        'italic' => ['I', 'i'],
        'underline' => ['U', 'u'],
        'strike' => ['S', 's'],
        'link' => ['Link', 'url'],
        'image' => ['Image', 'img'],
        'quote' => ['Quote', 'quote'],
        'code' => ['Code', 'code'],
        'list' => ['List', 'list'],
        'colour' => ['Colour', 'color'],
        'size' => ['Size', 'size'],
    ];

    public function actions(): array
    {
        return array_keys(self::INSERTIONS);
    }

    public function insertion(string $action): ?Insertion
    {
        if (! isset(self::INSERTIONS[$action])) {
            return null;
        }

        [$label, $tag] = self::INSERTIONS[$action];

        // Tags taking an argument prompt for it in the opening tag, so the
        // selection stays the visible text rather than the target.
        return match ($action) {
            'link' => new Insertion($action, $label, '[url=https://]', '[/url]', 'link text'),
            'image' => new Insertion($action, $label, '[img]', '[/img]', 'https://'),
            'colour' => new Insertion($action, $label, '[color=red]', '[/color]', 'text'),
            'size' => new Insertion($action, $label, '[size=5]', '[/size]', 'text'),

            'quote', 'code' => new Insertion(
                $action,
                $label,
                '['.$tag.']',
                '[/'.$tag.']',
                $action === 'code' ? 'code' : 'quoted text',
                block: true,
            ),

            // A list needs an item marker to be worth anything.
            'list' => new Insertion($action, $label, "[list]\n[*]", "\n[/list]", 'item', block: true),

            default => new Insertion($action, $label, '['.$tag.']', '[/'.$tag.']', 'text'),
        };
    }

    public function parse(string $source, Schema $schema): Document
    {
        // Normalise line endings first so paragraph splitting is consistent
        // regardless of where the text was authored.
        $normalised = str_replace(["\r\n", "\r"], "\n", $source);

        $tokens = $this->lexer->tokenise($normalised);

        $document = new Document;

        foreach ($this->blocks($tokens) as $node) {
            $document->append($node);
        }

        $schema->filter($document);

        return $document;
    }

    /**
     * Build the block structure of a token run.
     *
     * BBCode has no block grammar of its own — there is no heading syntax and no
     * indentation rule — so blocks come from two sources: block-level tags, and
     * blank lines separating paragraphs. Inline content between block tags is
     * gathered into paragraphs.
     *
     * @param  list<Token>  $tokens
     * @return list<Node>
     */
    private function blocks(array $tokens): array
    {
        $blocks = [];
        $inline = [];

        $flush = function () use (&$inline, &$blocks): void {
            foreach ($this->paragraphs($inline) as $paragraph) {
                $blocks[] = $paragraph;
            }

            $inline = [];
        };

        $index = 0;
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token->isOpen() && $this->kindOf($token->name) === Tag::BLOCK) {
                $end = $this->matchingClose($tokens, $index);

                if ($end === null) {
                    // Unclosed block tag: not a block, just text.
                    $inline[] = Token::text($token->source);
                    $index++;

                    continue;
                }

                $flush();

                $inner = array_slice($tokens, $index + 1, $end - $index - 1);
                $blocks[] = $this->block($token, $inner);

                $index = $end + 1;

                continue;
            }

            if ($token->isOpen() && $token->name === 'hr') {
                $flush();
                $blocks[] = new HorizontalRule;
                $index++;

                continue;
            }

            if ($token->isOpen() && $this->kindOf($token->name) === Tag::VERBATIM) {
                $end = $this->matchingClose($tokens, $index);

                if ($end === null) {
                    $inline[] = Token::text($token->source);
                    $index++;

                    continue;
                }

                $content = '';

                foreach (array_slice($tokens, $index + 1, $end - $index - 1) as $piece) {
                    $content .= $piece->source;
                }

                // A [code] on its own line is a block; inline in a sentence it
                // stays inline, as a code mark. Trackers use both.
                if ($this->standsAlone($tokens, $index, $end)) {
                    $flush();
                    $blocks[] = new CodeBlock($this->trimOneNewline($content), $token->argument);
                } else {
                    $inline[] = new Token(Token::OPEN, $token->source, 'code');
                    $inline[] = Token::text($content);
                    $inline[] = new Token(Token::CLOSE, '[/code]', 'code');
                }

                $index = $end + 1;

                continue;
            }

            $inline[] = $token;
            $index++;
        }

        $flush();

        return $blocks;
    }

    /**
     * Build one block-level tag's node.
     *
     * @param  list<Token>  $inner
     */
    private function block(Token $token, array $inner): Node
    {
        return match ($token->name) {
            'quote' => $this->quote($token, $inner),
            'list', 'ul', 'ol' => $this->list($token, $inner),
            default => new Paragraph,
        };
    }

    /**
     * @param  list<Token>  $inner
     */
    private function quote(Token $token, array $inner): Node
    {
        $author = $token->argument === null ? null : trim($token->argument);

        $quote = new BlockQuote($author === '' ? null : $author);

        // Quotes nest, and their content is a document in miniature.
        return $quote->appendAll($this->blocks($inner));
    }

    /**
     * Build a list from `[*]`-delimited items.
     *
     * BBCode list items have no closing tag: an item runs until the next `[*]`
     * or the end of the list. That is unlike everything in Markdown, and it is
     * why items are split here rather than paired by the lexer.
     *
     * @param  list<Token>  $inner
     */
    private function list(Token $token, array $inner): Node
    {
        // [list=1] is ordered in every dialect that supports it; [ol] likewise.
        $ordered = $token->name === 'ol'
            || ($token->argument !== null && $token->argument !== '');

        $list = $ordered ? new OrderedList($this->start($token)) : new BulletList;

        /** @var list<list<Token>> $items */
        $items = [];
        $current = null;

        foreach ($inner as $piece) {
            if ($piece->isOpen() && $piece->name === '*') {
                if ($current !== null) {
                    $items[] = $current;
                }

                $current = [];

                continue;
            }

            if ($current === null) {
                // Content before the first [*] is not in an item. Whitespace is
                // discarded; anything else opens an implicit first item so the
                // text is not lost.
                if ($piece->isText() && trim($piece->source) === '') {
                    continue;
                }

                $current = [];
            }

            $current[] = $piece;
        }

        if ($current !== null) {
            $items[] = $current;
        }

        foreach ($items as $item) {
            $node = new ListItem;

            // A list item's content is inline, and its trailing newline before
            // the next [*] is layout rather than a break.
            $node->appendAll($this->inline($this->trimEdges($item), []));

            $list->append($node);
        }

        return $list;
    }

    private function start(Token $token): int
    {
        $argument = trim((string) $token->argument);

        return preg_match('/^\d+$/', $argument) === 1 ? max(1, (int) $argument) : 1;
    }

    /**
     * Split an inline token run into paragraphs on blank lines.
     *
     * @param  list<Token>  $tokens
     * @return list<Node>
     */
    private function paragraphs(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        /** @var list<list<Token>> $groups */
        $groups = [[]];

        foreach ($tokens as $token) {
            if (! $token->isText()) {
                $groups[array_key_last($groups)][] = $token;

                continue;
            }

            // A blank line ends a paragraph. Single newlines are soft breaks and
            // are handled in inline().
            $pieces = preg_split('/\n[ \t]*\n\s*/', $token->source) ?: [];

            foreach ($pieces as $offset => $piece) {
                if ($offset > 0) {
                    $groups[] = [];
                }

                if ($piece !== '') {
                    $groups[array_key_last($groups)][] = Token::text($piece);
                }
            }
        }

        $paragraphs = [];

        foreach ($groups as $group) {
            $children = $this->inline($this->trimEdges($group), []);

            if ($children === []) {
                continue;
            }

            $paragraphs[] = (new Paragraph)->appendAll($children);
        }

        return $paragraphs;
    }

    /**
     * Build inline nodes from a token run, accumulating marks.
     *
     * Unclosed and mismatched inline tags degrade here: the opener becomes the
     * literal text it was and its content stays as siblings, so no input can
     * swallow the rest of a document.
     *
     * @param  list<Token>  $tokens
     * @param  list<Mark>  $marks
     * @return list<Node>
     */
    private function inline(array $tokens, array $marks): array
    {
        $nodes = [];
        $index = 0;
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token->isText()) {
                foreach ($this->text($token->source, $marks) as $node) {
                    $nodes[] = $node;
                }

                $index++;

                continue;
            }

            if ($token->isClose()) {
                // A closer with no opener is text.
                $nodes[] = new Text($token->source, $marks);
                $index++;

                continue;
            }

            if ($token->name === 'hr') {
                // Inline [hr] is meaningless; drop the tag, keep nothing.
                $index++;

                continue;
            }

            if ($token->name === 'img') {
                $end = $this->matchingClose($tokens, $index);

                if ($end === null) {
                    $nodes[] = new Text($token->source, $marks);
                    $index++;

                    continue;
                }

                $node = $this->image($token, array_slice($tokens, $index + 1, $end - $index - 1));

                if ($node !== null) {
                    $nodes[] = $node;
                }

                $index = $end + 1;

                continue;
            }

            $end = $this->matchingClose($tokens, $index);

            if ($end === null) {
                $nodes[] = new Text($token->source, $marks);
                $index++;

                continue;
            }

            $mark = $this->mark($token, array_slice($tokens, $index + 1, $end - $index - 1));
            $inner = array_slice($tokens, $index + 1, $end - $index - 1);

            foreach ($this->inline($inner, $mark === null ? $marks : [...$marks, $mark]) as $node) {
                $nodes[] = $node;
            }

            $index = $end + 1;
        }

        return $nodes;
    }

    /**
     * The mark an inline tag contributes, or null when it contributes none.
     *
     * @param  list<Token>  $inner
     */
    private function mark(Token $token, array $inner): ?Mark
    {
        return match ($token->name) {
            'b' => new Bold,
            'i' => new Italic,
            'u' => new Underline,
            's' => new Strike,
            'code' => new CodeMark,
            'color', 'colour' => $this->colour($token),
            'size' => $this->size($token),
            'url' => new Link($this->href($token, $inner)),
            default => null,
        };
    }

    private function colour(Token $token): ?Mark
    {
        $value = trim((string) $token->argument);

        // An unrecognised colour contributes no mark at all rather than an empty
        // one, so the text renders clean.
        return Colour::sanitise($value) === '' ? null : new Colour($value);
    }

    private function size(Token $token): ?Mark
    {
        $value = trim((string) $token->argument);

        return Size::sanitise($value) === '' ? null : new Size($value);
    }

    /**
     * A URL tag's target: its argument when given ([url=x]text[/url]), otherwise
     * its own content ([url]x[/url]).
     *
     * @param  list<Token>  $inner
     */
    private function href(Token $token, array $inner): string
    {
        if ($token->argument !== null && trim($token->argument) !== '') {
            return trim($token->argument);
        }

        return trim($this->raw($inner));
    }

    /**
     * @param  list<Token>  $inner
     */
    private function image(Token $token, array $inner): ?Node
    {
        // [img]url[/img] is the common form; [img=url]alt[/img] appears in some
        // dialects. Support both, preferring the argument.
        $src = $token->argument !== null && trim($token->argument) !== ''
            ? trim($token->argument)
            : trim($this->raw($inner));

        $alt = $token->argument !== null && trim($token->argument) !== ''
            ? trim($this->raw($inner))
            : '';

        if ($src === '' || Link::sanitiseHref($src) === '') {
            // A refused src is not rendered as a broken image; its alt text is
            // all that was worth keeping.
            return $alt === '' ? null : new Text($alt);
        }

        return new Image($src, $alt === '' ? null : $alt);
    }

    /**
     * Split literal text into Text nodes and hard breaks.
     *
     * A single newline inside a paragraph is a line break in BBCode, unlike
     * Markdown where it is a space. Trackers rely on this — BBCode posts are
     * written with hard-wrapped lines and expect them honoured.
     *
     * @param  list<Mark>  $marks
     * @return list<Node>
     */
    private function text(string $source, array $marks): array
    {
        if ($source === '') {
            return [];
        }

        if (! str_contains($source, "\n")) {
            return [new Text($source, $marks)];
        }

        $nodes = [];
        $lines = explode("\n", $source);

        foreach ($lines as $offset => $line) {
            if ($offset > 0) {
                $nodes[] = new HardBreak;
            }

            if ($line !== '') {
                $nodes[] = new Text($line, $marks);
            }
        }

        return $nodes;
    }

    /**
     * The source text of a token run, used where content must be a plain string
     * (a URL, an image reference) rather than a subtree.
     *
     * @param  list<Token>  $tokens
     */
    private function raw(array $tokens): string
    {
        $text = '';

        foreach ($tokens as $token) {
            $text .= $token->source;
        }

        return $text;
    }

    /**
     * Find the closer matching the opener at $start, honouring nesting of the
     * same tag, or null when it is never closed.
     *
     * @param  list<Token>  $tokens
     */
    private function matchingClose(array $tokens, int $start): ?int
    {
        $name = $tokens[$start]->name;
        $depth = 0;
        $count = count($tokens);

        for ($index = $start + 1; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->isOpen() && $token->name === $name) {
                $depth++;

                continue;
            }

            if (! $token->isClose() || $token->name !== $name) {
                continue;
            }

            if ($depth === 0) {
                return $index;
            }

            $depth--;
        }

        return null;
    }

    /**
     * Whether a tag pair occupies its own line, which decides whether [code] is
     * a block or an inline span.
     *
     * @param  list<Token>  $tokens
     */
    private function standsAlone(array $tokens, int $open, int $close): bool
    {
        $before = $open === 0 ? null : $tokens[$open - 1];
        $after = $tokens[$close + 1] ?? null;

        $startsLine = $before === null
            || ($before->isText() && preg_match('/\n[ \t]*$/', $before->source) === 1)
            || ! $before->isText();

        $endsLine = $after === null
            || ($after->isText() && preg_match('/^[ \t]*\n/', $after->source) === 1)
            || ! $after->isText();

        return $startsLine && $endsLine;
    }

    /**
     * Drop one leading and one trailing newline from verbatim content.
     *
     * `[code]\nfoo\n[/code]` means the code is "foo", not "\nfoo\n" — the
     * newlines are there to make the source readable. Only one is dropped from
     * each end, so deliberate blank lines survive.
     */
    private function trimOneNewline(string $content): string
    {
        if (str_starts_with($content, "\n")) {
            $content = substr($content, 1);
        }

        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }

        return $content;
    }

    /**
     * Trim whitespace-only text tokens from the ends of a run.
     *
     * @param  list<Token>  $tokens
     * @return list<Token>
     */
    private function trimEdges(array $tokens): array
    {
        while ($tokens !== [] && $tokens[0]->isText() && trim($tokens[0]->source) === '') {
            array_shift($tokens);
        }

        while ($tokens !== [] && $tokens[array_key_last($tokens)]->isText()
            && trim($tokens[array_key_last($tokens)]->source) === '') {
            array_pop($tokens);
        }

        if ($tokens === []) {
            return [];
        }

        // Interior newlines matter, but a leading or trailing one is layout.
        $first = $tokens[0];

        if ($first->isText()) {
            $tokens[0] = Token::text(preg_replace('/^[ \t]*\n\s*/', '', $first->source) ?? $first->source);
        }

        $lastKey = array_key_last($tokens);
        $last = $tokens[$lastKey];

        if ($last->isText()) {
            $tokens[$lastKey] = Token::text(preg_replace('/\s*\n[ \t]*$/', '', $last->source) ?? $last->source);
        }

        return array_values($tokens);
    }

    private function kindOf(string $name): ?string
    {
        return $this->tags[$name]->kind ?? null;
    }
}
