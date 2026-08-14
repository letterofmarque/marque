<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Marks\Colour;
use Marque\SquidInk\Document\Marks\Link;

describe('Link href sanitisation', function () {
    it('allows ordinary schemes', function (string $href) {
        expect((new Link($href))->href())->toBe($href);
    })->with([
        'https://example.com/x?y=1#z',
        'http://example.com',
        'mailto:someone@example.com',
        'ftp://files.example.com/x.iso',
        'magnet:?xt=urn:btih:abcdef',
    ]);

    it('allows relative and fragment URLs', function (string $href) {
        expect((new Link($href))->href())->toBe($href);
    })->with([
        '/torrents/1',
        'torrents/1',
        '#section',
        '?page=2',
        '/path/with:colon',
    ]);

    it('refuses script-bearing schemes', function (string $href) {
        expect((new Link($href))->href())->toBe('');
    })->with([
        'javascript:alert(1)',
        'JavaScript:alert(1)',
        'JAVASCRIPT:alert(1)',
        'vbscript:msgbox(1)',
        'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
        'file:///etc/passwd',
    ]);

    it('refuses schemes smuggled past a naive check', function (string $href) {
        // Control characters and whitespace inside the scheme are stripped
        // before checking, because browsers ignore them when parsing URLs.
        expect((new Link($href))->href())->toBe('');
    })->with([
        "java\tscript:alert(1)",
        "java\nscript:alert(1)",
        "java\rscript:alert(1)",
        " javascript:alert(1)",
        "jav\0ascript:alert(1)",
    ]);

    it('treats an empty href as empty', function () {
        expect((new Link(''))->href())->toBe('')
            ->and((new Link('   '))->href())->toBe('');
    });

    it('keeps an optional title', function () {
        expect((new Link('https://example.com', 'A title'))->title())->toBe('A title')
            ->and((new Link('https://example.com'))->title())->toBeNull();
    });
});

describe('Colour sanitisation', function () {
    it('allows hex codes', function (string $colour) {
        expect((new Colour($colour))->colour())->toBe(strtolower($colour));
    })->with(['#fff', '#FFF', '#a1b2c3', '#000000']);

    it('allows named colours', function () {
        expect((new Colour('red'))->colour())->toBe('red')
            ->and((new Colour('RED'))->colour())->toBe('red');
    });

    it('refuses anything else', function (string $colour) {
        // A colour reaching a style attribute unvalidated is CSS injection.
        expect((new Colour($colour))->colour())->toBe('');
    })->with([
        'red; background: url(evil)',
        'expression(alert(1))',
        'url(javascript:alert(1))',
        '#gggggg',
        '#12345',
        'rgb(255,0,0)',
    ]);
});
