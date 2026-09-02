<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Marks\Size;
use Marque\SquidInk\Document\Node;
use Marque\SquidInk\Document\Nodes\BlockQuote;
use Marque\SquidInk\Document\Nodes\CodeBlock;
use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Nodes\Image;
use Marque\SquidInk\Document\Nodes\OrderedList;
use Marque\SquidInk\Document\Nodes\Text;
use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Parsers\BBCodeParser;
use Marque\SquidInk\Renderers\HtmlRenderer;

function parseBBCode(string $source, ?Schema $schema = null): Document
{
    return (new BBCodeParser)->parse($source, $schema ?? Schema::permissive());
}

function renderBBCode(string $source): string
{
    return (new HtmlRenderer)->render(parseBBCode($source));
}

/**
 * The first Text node in a tree, for asserting marks.
 */
function firstText(Node $node): ?Text
{
    foreach ($node->walk() as $candidate) {
        if ($candidate instanceof Text) {
            return $candidate;
        }
    }

    return null;
}

describe('BBCodeParser inline marks', function () {
    it('parses the basic emphasis tags', function (string $tag, string $mark) {
        $doc = parseBBCode("[{$tag}]text[/{$tag}]");

        expect(firstText($doc)?->hasMark($mark))->toBeTrue();
    })->with([
        ['b', 'bold'],
        ['i', 'italic'],
        ['u', 'underline'],
        ['s', 'strike'],
    ]);

    it('nests marks', function () {
        $doc = parseBBCode('[b][i]both[/i][/b]');

        $text = firstText($doc);

        expect($text?->hasMark('bold'))->toBeTrue()
            ->and($text?->hasMark('italic'))->toBeTrue()
            ->and($text?->text())->toBe('both');
    });

    it('parses [url] with the target as its content', function () {
        $doc = parseBBCode('[url]https://example.com[/url]');

        $mark = firstText($doc)?->mark('link');

        expect($mark?->attribute('href'))->toBe('https://example.com');
    });

    it('parses [url=target] with separate label text', function () {
        $doc = parseBBCode('[url=https://example.com]the label[/url]');

        $text = firstText($doc);

        expect($text?->text())->toBe('the label')
            ->and($text?->mark('link')?->attribute('href'))->toBe('https://example.com');
    });

    it('accepts a quoted argument so a URL containing a bracket survives', function () {
        $doc = parseBBCode('[url="https://example.com/a]b"]label[/url]');

        expect(firstText($doc)?->mark('link')?->attribute('href'))
            ->toBe('https://example.com/a]b');
    });

    it('refuses a javascript: URL, keeping the text', function () {
        $html = renderBBCode('[url=javascript:alert(1)]click[/url]');

        expect($html)->toBe('<p>click</p>')
            ->and($html)->not->toContain('javascript');
    });

    it('parses [color] in both spellings', function (string $tag) {
        $doc = parseBBCode("[{$tag}=red]text[/{$tag}]");

        expect(firstText($doc)?->mark('colour')?->attribute('colour'))->toBe('red');
    })->with(['color', 'colour']);

    it('drops an unrecognised colour rather than emitting an empty mark', function () {
        $doc = parseBBCode('[color=url(evil)]text[/color]');

        expect(firstText($doc)?->hasMark('colour'))->toBeFalse()
            ->and(firstText($doc)?->text())->toBe('text');
    });
});

describe('BBCodeParser size', function () {
    it('parses the 1-7 scale', function () {
        $doc = parseBBCode('[size=5]big[/size]');

        expect(firstText($doc)?->mark('size')?->attribute('size'))->toBe('5');
    });

    it('maps named sizes onto the scale', function () {
        $doc = parseBBCode('[size=huge]loud[/size]');

        expect(firstText($doc)?->mark('size')?->attribute('size'))->toBe('7');
    });

    it('clamps an out-of-range size instead of failing', function () {
        expect(Size::sanitise('99'))->toBe('7');
    });

    it('maps a px value onto the nearest step', function () {
        expect(Size::sanitise('16px'))->toBe('3');
    });

    it('renders a size from the fixed scale, never the raw value', function () {
        $html = renderBBCode('[size=5]big[/size]');

        expect($html)->toBe('<p><span style="font-size:1.5em">big</span></p>');
    });

    it('drops an unparseable size', function () {
        $doc = parseBBCode('[size=1em;color:red]x[/size]');

        expect(firstText($doc)?->hasMark('size'))->toBeFalse();
    });

    it('is absent from the minimal schema, so narrow fields cannot be shouted at', function () {
        $doc = parseBBCode('[size=7]loud[/size]', Schema::minimal());

        expect(firstText($doc)?->hasMark('size'))->toBeFalse()
            ->and(firstText($doc)?->text())->toBe('loud');
    });
});

describe('BBCodeParser blocks', function () {
    it('splits paragraphs on blank lines', function () {
        $doc = parseBBCode("one\n\ntwo");

        expect($doc->children())->toHaveCount(2)
            ->and($doc->children()[0]->type())->toBe('paragraph')
            ->and($doc->children()[1]->type())->toBe('paragraph');
    });

    it('treats a single newline as a hard break, unlike Markdown', function () {
        $html = renderBBCode("one\ntwo");

        expect($html)->toBe('<p>one<br>two</p>');
    });

    it('parses [quote] as a block quote', function () {
        $doc = parseBBCode('[quote]quoted[/quote]');

        expect($doc->children()[0]->type())->toBe('block_quote');
    });

    it('keeps the author from [quote=name]', function () {
        $doc = parseBBCode('[quote=someone]quoted[/quote]');

        $quote = $doc->children()[0];

        expect($quote)->toBeInstanceOf(BlockQuote::class)
            ->and($quote->author())->toBe('someone');
    });

    it('renders an attributed quote with a cite', function () {
        $html = renderBBCode('[quote=someone]hi[/quote]');

        expect($html)->toBe('<blockquote><cite>someone</cite><p>hi</p></blockquote>');
    });

    it('escapes an author name', function () {
        $html = renderBBCode('[quote=<script>]hi[/quote]');

        expect($html)->toContain('&lt;script&gt;')
            ->and($html)->not->toContain('<script>');
    });

    it('nests quotes', function () {
        $doc = parseBBCode('[quote=a]outer [quote=b]inner[/quote][/quote]');

        $outer = $doc->children()[0];

        $inner = null;

        foreach ($outer->walk() as $node) {
            if ($node !== $outer && $node->type() === 'block_quote') {
                $inner = $node;
            }
        }

        expect($outer->author())->toBe('a')
            ->and($inner)->not->toBeNull()
            ->and($inner->author())->toBe('b');
    });

    it('parses [hr] as a horizontal rule', function () {
        $doc = parseBBCode("above\n\n[hr]\n\nbelow");

        expect(array_map(fn ($n) => $n->type(), $doc->children()))
            ->toBe(['paragraph', 'horizontal_rule', 'paragraph']);
    });
});

describe('BBCodeParser lists', function () {
    it('parses [*] items with no closing tag', function () {
        $doc = parseBBCode('[list][*]one[*]two[/list]');

        $list = $doc->children()[0];

        expect($list->type())->toBe('bullet_list')
            ->and($list->children())->toHaveCount(2)
            ->and($list->children()[0]->type())->toBe('list_item');
    });

    it('parses items written across lines', function () {
        $doc = parseBBCode("[list]\n[*]one\n[*]two\n[/list]");

        $list = $doc->children()[0];

        expect($list->children())->toHaveCount(2)
            ->and(firstText($list->children()[0])?->text())->toBe('one');
    });

    it('treats [list=1] as ordered', function () {
        $doc = parseBBCode('[list=1][*]one[/list]');

        expect($doc->children()[0])->toBeInstanceOf(OrderedList::class);
    });

    it('treats [ol] as ordered and [ul] as bullet', function () {
        expect(parseBBCode('[ol][*]a[/ol]')->children()[0]->type())->toBe('ordered_list')
            ->and(parseBBCode('[ul][*]a[/ul]')->children()[0]->type())->toBe('bullet_list');
    });

    it('keeps marks inside items', function () {
        $doc = parseBBCode('[list][*][b]bold[/b] item[/list]');

        $item = $doc->children()[0]->children()[0];

        expect(firstText($item)?->hasMark('bold'))->toBeTrue();
    });

    it('does not lose text written before the first [*]', function () {
        $doc = parseBBCode('[list]stray[*]one[/list]');

        $list = $doc->children()[0];

        expect($list->children())->toHaveCount(2)
            ->and(firstText($list->children()[0])?->text())->toBe('stray');
    });
});

describe('BBCodeParser verbatim content', function () {
    it('parses a standalone [code] as a code block', function () {
        $doc = parseBBCode("[code]\nplain\n[/code]");

        expect($doc->children()[0])->toBeInstanceOf(CodeBlock::class)
            ->and($doc->children()[0]->code())->toBe('plain');
    });

    it('does not parse tags inside [code]', function () {
        $doc = parseBBCode("[code]\n[b]not bold[/b]\n[/code]");

        expect($doc->children()[0]->code())->toBe('[b]not bold[/b]');
    });

    it('preserves NFO whitespace byte for byte', function () {
        $art = "  ___\n /   \\   [*]  |__|\n \\___/";

        $doc = parseBBCode("[code]\n{$art}\n[/code]");

        expect($doc->children()[0]->code())->toBe($art);
    });

    it('keeps a language argument', function () {
        $doc = parseBBCode("[code=php]\n\$x = 1;\n[/code]");

        expect($doc->children()[0]->language())->toBe('php');
    });

    it('escapes code content on render', function () {
        $html = renderBBCode("[code]\n<script>alert(1)</script>\n[/code]");

        expect($html)->toContain('&lt;script&gt;')
            ->and($html)->not->toContain('<script>');
    });

    it('treats inline [code] as a code mark rather than a block', function () {
        $doc = parseBBCode('use [code]foo()[/code] here');

        expect($doc->children()[0]->type())->toBe('paragraph');

        $marked = null;

        foreach ($doc->walk() as $node) {
            if ($node instanceof Text && $node->hasMark('code')) {
                $marked = $node;
            }
        }

        expect($marked?->text())->toBe('foo()');
    });
});

describe('BBCodeParser images', function () {
    it('parses [img]url[/img]', function () {
        $doc = parseBBCode('[img]https://example.com/a.png[/img]');

        $image = null;

        foreach ($doc->walk() as $node) {
            if ($node instanceof Image) {
                $image = $node;
            }
        }

        expect($image?->src())->toBe('https://example.com/a.png');
    });

    it('parses [img=url]alt[/img]', function () {
        $doc = parseBBCode('[img=https://example.com/a.png]a picture[/img]');

        $image = null;

        foreach ($doc->walk() as $node) {
            if ($node instanceof Image) {
                $image = $node;
            }
        }

        expect($image?->src())->toBe('https://example.com/a.png')
            ->and($image?->alt())->toBe('a picture');
    });

    it('refuses a javascript: src', function () {
        $html = renderBBCode('[img]javascript:alert(1)[/img]');

        expect($html)->not->toContain('javascript')
            ->and($html)->not->toContain('<img');
    });
});

describe('BBCodeParser malformed input is inert', function () {
    it('leaves an unknown tag as literal text', function () {
        $html = renderBBCode('[blink]text[/blink]');

        expect($html)->toBe('<p>[blink]text[/blink]</p>');
    });

    it('leaves an unclosed inline tag as literal text', function () {
        $html = renderBBCode('[b]never closed');

        expect($html)->toBe('<p>[b]never closed</p>');
    });

    it('keeps content after an unclosed tag rather than swallowing it', function () {
        $html = renderBBCode('[b]one [i]two');

        expect($html)->toContain('one')
            ->and($html)->toContain('two')
            ->and($html)->not->toContain('<strong>');
    });

    it('leaves a stray closer as literal text', function () {
        $html = renderBBCode('text[/b]');

        expect($html)->toBe('<p>text[/b]</p>');
    });

    it('leaves an unclosed block tag as text', function () {
        $html = renderBBCode('[quote]dangling');

        expect($html)->toBe('<p>[quote]dangling</p>');
    });

    it('leaves an unclosed [code] as text rather than eating the document', function () {
        $html = renderBBCode('[code]dangling');

        expect($html)->toContain('[code]')
            ->and($html)->toContain('dangling');
    });

    it('handles crossed tags without emitting broken markup', function () {
        $html = renderBBCode('[b]one[i]two[/b]three[/i]');

        expect($html)->toStartWith('<p>')
            ->and($html)->toEndWith('</p>')
            ->and(substr_count($html, '<strong>'))->toBe(substr_count($html, '</strong>'))
            ->and(substr_count($html, '<em>'))->toBe(substr_count($html, '</em>'));
    });

    it('never throws on any of a pile of hostile inputs', function (string $source) {
        expect(fn () => renderBBCode($source))->not->toThrow(Throwable::class);
    })->with([
        '[',
        ']',
        '[]',
        '[/]',
        '[=]',
        '[b',
        '[b=',
        '[b][/i]',
        '[/b][b]',
        '[url=][/url]',
        '[img][/img]',
        '[list][/list]',
        '[list][*][/list]',
        '[quote=][/quote]',
        '[size=][/size]',
        '[color=][/color]',
        '[code][/code]',
        '[b][b][b][b][b]deep',
        '[*]orphan item',
        "[code]\n[code]\n[/code]",
    ]);

    it('emits no raw HTML from any tag argument', function () {
        $html = renderBBCode('[color="><script>alert(1)</script>]x[/color]');

        expect($html)->not->toContain('<script>');
    });

    it('escapes bare HTML in the source', function () {
        $html = renderBBCode('<script>alert(1)</script>');

        expect($html)->toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
    });
});

describe('BBCodeParser identity', function () {
    it('registers under a stable name', function () {
        expect((new BBCodeParser)->name())->toBe('bbcode');
    });

    it('produces an empty document from empty source', function () {
        expect(parseBBCode('')->children())->toBe([]);
    });

    it('normalises CRLF line endings', function () {
        $html = renderBBCode("one\r\n\r\ntwo");

        expect($html)->toBe('<p>one</p><p>two</p>');
    });
});
