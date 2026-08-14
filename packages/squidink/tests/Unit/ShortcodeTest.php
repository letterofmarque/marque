<?php

declare(strict_types=1);

use Marque\SquidInk\SquidInk;

function convertHtml(string $source): string
{
    return app(SquidInk::class)->convert($source);
}

function convertText(string $source): string
{
    return app(SquidInk::class)->convert($source, 'markdown', 'text');
}

describe('Shortcode parsing', function () {
    it('turns a registered paired shortcode into a node', function () {
        expect(convertHtml('{spoiler}hidden{/spoiler}'))
            ->toContain('<details class="squidink-spoiler">')
            ->and(convertHtml('{spoiler}hidden{/spoiler}'))->toContain('hidden');
    });

    it('reads attributes', function () {
        expect(convertHtml('{spoiler title="The Ending"}x{/spoiler}'))
            ->toContain('<summary>The Ending</summary>');
    });

    it('reads unquoted attributes', function () {
        expect(convertHtml('{spoiler title=Ending}x{/spoiler}'))
            ->toContain('<summary>Ending</summary>');
    });

    it('defaults a missing attribute', function () {
        expect(convertHtml('{spoiler}x{/spoiler}'))
            ->toContain('<summary>Spoiler</summary>');
    });

    it('escapes attribute values', function () {
        $html = convertHtml('{spoiler title="\"><script>alert(1)</script>"}x{/spoiler}');

        expect($html)->not->toContain('<script>');
    });

    it('keeps surrounding text', function () {
        $html = convertHtml('before {spoiler}mid{/spoiler} after');

        expect($html)->toContain('before ')
            ->and($html)->toContain('after');
    });

    it('nests shortcodes', function () {
        $html = convertHtml('{spoiler}outer {spoiler}inner{/spoiler}{/spoiler}');

        expect(substr_count($html, '<details'))->toBe(2);
    });
});

describe('Shortcode block handling', function () {
    it('lifts a block-level shortcode out of its paragraph', function () {
        // <details> is flow content — a browser silently closes a <p> before
        // it, so leaving the wrapper in place breaks the nesting.
        $html = convertHtml("Some text.\n\n{spoiler}\nhidden\n{/spoiler}\n\nMore.");

        expect($html)->not->toContain('<p><details');
    });

    it('keeps an inline shortcode inside its paragraph', function () {
        $html = convertHtml('before {spoiler}x{/spoiler} after');

        expect($html)->toStartWith('<p>');
    });
});

describe('Shortcode robustness', function () {
    it('leaves unregistered shortcodes as literal text', function () {
        // Content written on a site with more shortcodes should still read.
        expect(convertHtml('{unknownthing}x{/unknownthing}'))
            ->toContain('{unknownthing}');
    });

    it('leaves an unclosed shortcode as text, keeping its content', function () {
        $html = convertHtml('{spoiler}never closed');

        expect($html)->toContain('{spoiler}')
            ->and($html)->toContain('never closed')
            ->and($html)->not->toContain('<details');
    });

    it('leaves a stray closing tag as text', function () {
        expect(convertHtml('nothing opened {/spoiler}'))
            ->toContain('{/spoiler}');
    });

    it('does not treat braces in ordinary text as shortcodes', function () {
        expect(convertHtml('use {} for an empty set'))
            ->toContain('{}');
    });

    it('never throws on malformed input', function (string $source) {
        expect(fn () => convertHtml($source))->not->toThrow(Throwable::class);
    })->with([
        '{',
        '}',
        '{/}',
        '{spoiler',
        '{spoiler}{spoiler}{/spoiler}',
        '{/spoiler}{spoiler}',
        '{spoiler title=}x{/spoiler}',
        '{{{{}}}}',
    ]);
});

describe('Shortcode rendering per format', function () {
    it('renders a spoiler as its content in plain text', function () {
        // A search index should still find what is inside a spoiler.
        expect(convertText('{spoiler}the butler did it{/spoiler}'))
            ->toBe('the butler did it');
    });

    it('omits mediainfo from plain text', function () {
        // A MediaInfo dump in a search index drowns the actual description.
        expect(convertText('before {mediainfo}Format: Matroska{/mediainfo} after'))
            ->not->toContain('Matroska');
    });

    it('renders mediainfo as a collapsed block in html', function () {
        expect(convertHtml('{mediainfo}Format: Matroska{/mediainfo}'))
            ->toContain('squidink-mediainfo')
            ->and(convertHtml('{mediainfo}Format: Matroska{/mediainfo}'))
            ->toContain('Matroska');
    });
});
