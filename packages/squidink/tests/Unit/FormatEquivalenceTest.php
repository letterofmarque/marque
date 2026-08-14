<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Parsers\BBCodeParser;
use Marque\SquidInk\Parsers\MarkdownParser;
use Marque\SquidInk\Renderers\HtmlRenderer;
use Marque\SquidInk\Renderers\PlainTextRenderer;

/**
 * The proof that the document model is genuinely format-agnostic.
 *
 * Per Spec #80, the whole thesis is that no input syntax is privileged: Markdown
 * and BBCode are peers that both map onto the same IR. The test of that claim is
 * that the same document, written in either syntax, renders identically — if it
 * does not, the IR is leaking one dialect's assumptions.
 *
 * Where the two genuinely cannot express the same thing (BBCode's [size], [u]
 * and quote attribution; Markdown's headings) the difference is a documented
 * asymmetry rather than a bug, and is tested separately below.
 */
function html(string $source, string $format): string
{
    $parser = $format === 'markdown' ? new MarkdownParser : new BBCodeParser;

    return (new HtmlRenderer)->render($parser->parse($source, Schema::permissive()));
}

function plain(string $source, string $format): string
{
    $parser = $format === 'markdown' ? new MarkdownParser : new BBCodeParser;

    return (new PlainTextRenderer)->render($parser->parse($source, Schema::permissive()));
}

describe('the same document in either syntax renders identically', function () {
    it('renders equivalent HTML', function (string $markdown, string $bbcode) {
        expect(html($bbcode, 'bbcode'))->toBe(html($markdown, 'markdown'));
    })->with([
        'plain paragraph' => ['hello world', 'hello world'],
        'bold' => ['**bold**', '[b]bold[/b]'],
        'italic' => ['*italic*', '[i]italic[/i]'],
        'strikethrough' => ['~~gone~~', '[s]gone[/s]'],
        'bold inside a sentence' => ['a **b** c', 'a [b]b[/b] c'],
        'nested emphasis' => ['***both***', '[b][i]both[/i][/b]'],
        'link with a label' => [
            '[the label](https://example.com)',
            '[url=https://example.com]the label[/url]',
        ],
        'inline code' => ['use `foo()` here', 'use [code]foo()[/code] here'],
        'two paragraphs' => ["one\n\ntwo", "one\n\ntwo"],
        'horizontal rule' => ["above\n\n---\n\nbelow", "above\n\n[hr]\n\nbelow"],
        'bullet list' => ["- one\n- two", '[list][*]one[*]two[/list]'],
        'plain quote' => ['> quoted', '[quote]quoted[/quote]'],
        'image' => [
            '![alt](https://example.com/a.png)',
            '[img=https://example.com/a.png]alt[/img]',
        ],
    ]);

    it('renders equivalent plain text', function (string $markdown, string $bbcode) {
        expect(plain($bbcode, 'bbcode'))->toBe(plain($markdown, 'markdown'));
    })->with([
        'bold' => ['**bold**', '[b]bold[/b]'],
        'link' => [
            '[label](https://example.com)',
            '[url=https://example.com]label[/url]',
        ],
        'two paragraphs' => ["one\n\ntwo", "one\n\ntwo"],
        'quote' => ['> quoted', '[quote]quoted[/quote]'],
        'bullet list' => ["- one\n- two", '[list][*]one[*]two[/list]'],
    ]);

    it('renders a nested structure identically', function () {
        $markdown = "> **bold** and a [link](https://example.com)\n";
        $bbcode = '[quote][b]bold[/b] and a [url=https://example.com]link[/url][/quote]';

        expect(html($bbcode, 'bbcode'))->toBe(html($markdown, 'markdown'));
    });

    it('keeps a code block byte-identical across both syntaxes', function () {
        $art = "  ___\n /   \\\n \\___/";

        $markdown = "```\n{$art}\n```";
        $bbcode = "[code]\n{$art}\n[/code]";

        expect(html($bbcode, 'bbcode'))->toBe(html($markdown, 'markdown'))
            ->and(html($bbcode, 'bbcode'))->toContain($art);
    });

    it('refuses a hostile URL the same way in both syntaxes', function () {
        expect(html('[click](javascript:alert(1))', 'markdown'))
            ->toBe(html('[url=javascript:alert(1)]click[/url]', 'bbcode'));
    });

    it('strips raw HTML the same way in both syntaxes', function () {
        expect(html('<script>alert(1)</script>', 'bbcode'))
            ->toContain('&lt;script&gt;');

        // Markdown strips the tag entirely (html_input => strip); BBCode escapes
        // it as text. Both are inert, which is the property that matters.
        expect(html('<script>alert(1)</script>', 'markdown'))
            ->not->toContain('<script>');
    });
});

describe('documented asymmetries between the two dialects', function () {
    it('has no Markdown equivalent for a sized span', function () {
        expect(html('[size=5]big[/size]', 'bbcode'))
            ->toContain('font-size')
            ->and(html('[size=5]big[/size]', 'markdown'))
            ->not->toContain('font-size');
    });

    it('has no Markdown equivalent for underline', function () {
        expect(html('[u]under[/u]', 'bbcode'))->toBe('<p><u>under</u></p>');
    });

    it('has no Markdown equivalent for quote attribution', function () {
        expect(html('[quote=someone]hi[/quote]', 'bbcode'))->toContain('<cite>someone</cite>')
            ->and(html('> hi', 'markdown'))->not->toContain('<cite>');
    });

    it('has no BBCode equivalent for headings', function () {
        expect(html('## Heading', 'markdown'))->toBe('<h2>Heading</h2>')
            ->and(html('## Heading', 'bbcode'))->toBe('<p>## Heading</p>');
    });

    it('differs on a single newline, which is correct for each dialect', function () {
        // Markdown joins soft-wrapped lines; BBCode honours the break. Both are
        // what a user of that syntax expects, so the IR must carry the
        // difference rather than normalise it away.
        // Markdown emits a literal newline, which HTML collapses to a space —
        // the soft wrap disappears, as CommonMark specifies. BBCode emits a
        // <br>, so the break the author typed survives.
        expect(html("one\ntwo", 'markdown'))->toBe("<p>one\ntwo</p>");
        expect(html("one\ntwo", 'bbcode'))->toBe('<p>one<br>two</p>');
    });
});

describe('both parsers respect the schema identically', function () {
    it('drops the same marks under a minimal schema', function () {
        $markdown = (new MarkdownParser)->parse('**bold** *and* `code`', Schema::minimal());
        $bbcode = (new BBCodeParser)->parse('[b]bold[/b] [i]and[/i] [code]code[/code]', Schema::minimal());

        $renderer = new HtmlRenderer;

        expect($renderer->render($bbcode))->toBe($renderer->render($markdown));
    });

    it('drops images under a minimal schema in both syntaxes', function () {
        $markdown = (new MarkdownParser)->parse('![alt](https://example.com/a.png)', Schema::minimal());
        $bbcode = (new BBCodeParser)->parse('[img=https://example.com/a.png]alt[/img]', Schema::minimal());

        expect(nodeTypes($markdown))->not->toContain('image')
            ->and(nodeTypes($bbcode))->not->toContain('image');
    });
});
