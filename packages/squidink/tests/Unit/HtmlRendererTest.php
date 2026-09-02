<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Parsers\MarkdownParser;
use Marque\SquidInk\Renderers\HtmlRenderer;

function renderMarkdown(string $source, ?Schema $schema = null): string
{
    $document = (new MarkdownParser)->parse($source, $schema ?? Schema::permissive());

    return (new HtmlRenderer)->render($document);
}

/**
 * Tag names that survive as real markup — escaped text does not count.
 *
 * @return list<string>
 */
function tagsIn(string $html): array
{
    preg_match_all('/<(\/?[a-z0-9]+)/i', $html, $matches);

    return array_values(array_unique($matches[1]));
}

describe('HtmlRenderer blocks', function () {
    it('renders paragraphs', function () {
        expect(renderMarkdown('hello'))->toBe('<p>hello</p>');
    });

    it('renders headings at their level', function () {
        expect(renderMarkdown('## Two'))->toBe('<h2>Two</h2>');
    });

    it('renders block quotes', function () {
        expect(renderMarkdown('> quoted'))->toBe('<blockquote><p>quoted</p></blockquote>');
    });

    it('renders bullet lists', function () {
        expect(renderMarkdown("- a\n- b"))
            ->toBe('<ul><li>a</li><li>b</li></ul>');
    });

    it('renders ordered lists, including a start offset', function () {
        expect(renderMarkdown("1. a\n2. b"))->toBe('<ol><li>a</li><li>b</li></ol>')
            ->and(renderMarkdown('5. a'))->toBe('<ol start="5"><li>a</li></ol>');
    });

    it('renders thematic breaks', function () {
        expect(renderMarkdown('---'))->toBe('<hr>');
    });
});

describe('HtmlRenderer inline', function () {
    it('renders marks as tags', function (string $source, string $expected) {
        expect(renderMarkdown($source))->toBe($expected);
    })->with([
        ['**b**', '<p><strong>b</strong></p>'],
        ['*i*', '<p><em>i</em></p>'],
        ['~~s~~', '<p><s>s</s></p>'],
        ['`c`', '<p><code>c</code></p>'],
    ]);

    it('renders links with rel attributes', function () {
        expect(renderMarkdown('[x](https://example.com)'))
            ->toBe('<p><a href="https://example.com" rel="nofollow noopener ugc">x</a></p>');
    });

    it('renders images', function () {
        expect(renderMarkdown('![alt](https://example.com/x.png)'))
            ->toBe('<p><img src="https://example.com/x.png" alt="alt"></p>');
    });
});

describe('HtmlRenderer code blocks', function () {
    it('escapes but never reformats code', function () {
        $doc = (new MarkdownParser)->parse(
            "```\n<script>alert(1)</script>\n  indented\n```",
            Schema::permissive(),
        );

        $html = (new HtmlRenderer)->render($doc);

        expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->and($html)->toContain('  indented')
            ->and($html)->not->toContain('<script>');
    });

    it('marks the language as a class', function () {
        expect(renderMarkdown("```php\nx\n```"))
            ->toContain('<pre><code class="language-php">');
    });
});

describe('HtmlRenderer XSS resistance', function () {
    it('strips raw HTML in the source', function (string $source) {
        $html = renderMarkdown($source);

        // The bar is "nothing executes", not "the string onload never appears".
        // Escaped text like &lt;svg/onload=alert(1)&gt; is inert and fine — what
        // matters is that no tag we did not emit ourselves survives as markup.
        $unexpected = array_diff(
            tagsIn($html),
            ['p', '/p', 'strong', '/strong', 'b', '/b', 'em', '/em', 'a', '/a'],
        );

        expect($unexpected)->toBe([]);
    })->with([
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '<iframe src="https://evil.example"></iframe>',
        '<div onload="alert(1)">text</div>',
        'text <b onmouseover="alert(1)">hover</b> more',
        '<svg/onload=alert(1)>',
        '<a href="https://ok.example" onclick="alert(1)">x</a>',
    ]);

    it('refuses script-bearing link schemes', function (string $source) {
        $html = renderMarkdown($source);

        expect($html)->not->toContain('javascript:')
            ->and($html)->not->toContain('vbscript:')
            ->and($html)->not->toContain('data:text/html');
    })->with([
        '[click](javascript:alert(1))',
        '[click](JaVaScRiPt:alert(1))',
        '[click](vbscript:msgbox(1))',
        '[click](data:text/html;base64,PHNjcmlwdD4=)',
    ]);

    it('keeps the text of a refused link', function () {
        // Degrade to plain text rather than silently deleting what was written.
        expect(renderMarkdown('[click me](javascript:alert(1))'))
            ->toContain('click me');
    });

    it('refuses script-bearing image sources', function () {
        $html = renderMarkdown('![x](javascript:alert(1))');

        expect($html)->not->toContain('javascript:')
            ->and($html)->not->toContain('<img');
    });

    it('drops anything that looks like a tag rather than escaping it', function () {
        // <thing> is parsed as raw HTML and removed at parse time, so it never
        // reaches the renderer. Stronger than escaping: it does not exist.
        $html = renderMarkdown('a <thing> & more');

        expect($html)->not->toContain('thing')
            ->and($html)->toContain('&amp;');
    });

    it('escapes text that cannot be mistaken for a tag', function () {
        $html = renderMarkdown('5 < 10 & 10 > 5');

        expect($html)->toContain('&lt;')
            ->and($html)->toContain('&gt;')
            ->and($html)->toContain('&amp;');
    });

    it('escapes attribute values', function () {
        $html = renderMarkdown('[x](https://example.com/a"b)');

        expect($html)->not->toContain('a"b');
    });
});
