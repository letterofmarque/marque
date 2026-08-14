<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Parsers\BBCodeParser;
use Marque\SquidInk\Renderers\HtmlRenderer;
use Marque\SquidInk\Shortcodes\MediaInfoShortcode;
use Marque\SquidInk\Shortcodes\ShortcodeParser;
use Marque\SquidInk\Shortcodes\ShortcodeRegistry;
use Marque\SquidInk\Shortcodes\SpoilerShortcode;
use Marque\SquidInk\SquidInk;

/**
 * Shortcodes and BBCode share a document but not a delimiter.
 *
 * Shortcodes use braces specifically so they are unambiguous alongside BBCode's
 * square brackets — the decision was made when shortcodes were built, and these
 * tests are what hold it in place now that a bracket-based syntax actually
 * exists.
 */
function bbcodeInk(): SquidInk
{
    $registry = new ShortcodeRegistry;
    $registry->register(new SpoilerShortcode);
    $registry->register(new MediaInfoShortcode);

    $ink = new SquidInk(Schema::permissive(), 'bbcode', new ShortcodeParser($registry));

    $ink->registerParser(new BBCodeParser);
    $ink->registerRenderer(new HtmlRenderer($registry));

    return $ink;
}

describe('shortcodes alongside BBCode', function () {
    it('renders BBCode marks inside a shortcode', function () {
        $html = bbcodeInk()->convert('{spoiler}[b]hidden[/b]{/spoiler}', 'bbcode');

        expect($html)->toContain('<strong>hidden</strong>')
            ->and($html)->toContain('squidink-spoiler');
    });

    it('renders a shortcode inline within BBCode text', function () {
        $html = bbcodeInk()->convert('before {spoiler}secret{/spoiler} after', 'bbcode');

        expect($html)->toContain('before ')
            ->and($html)->toContain(' after')
            ->and($html)->toContain('squidink-spoiler');
    });

    it('renders a shortcode inside a BBCode quote', function () {
        $html = bbcodeInk()->convert('[quote=dan]see {spoiler}this{/spoiler}[/quote]', 'bbcode');

        expect($html)->toContain('<cite>dan</cite>')
            ->and($html)->toContain('squidink-spoiler');
    });

    it('leaves brace syntax inside [code] alone', function () {
        $html = bbcodeInk()->convert(
            "[code]\n{spoiler}not a shortcode{/spoiler}\n[/code]",
            'bbcode',
        );

        // Verbatim means verbatim: a code sample containing brace syntax is a
        // code sample, not a shortcode.
        expect($html)->toContain('{spoiler}not a shortcode{/spoiler}')
            ->and($html)->not->toContain('squidink-spoiler');
    });

    it('leaves an unregistered shortcode as text without disturbing BBCode', function () {
        $html = bbcodeInk()->convert('[b]bold[/b] {nosuch}x{/nosuch}', 'bbcode');

        expect($html)->toContain('<strong>bold</strong>')
            ->and($html)->toContain('{nosuch}');
    });
});
