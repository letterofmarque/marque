<?php

declare(strict_types=1);

describe('SquidInkServiceProvider', function () {
    it('registers the squidink config', function () {
        expect(config('squidink'))->toBeArray();
    });

    it('defaults to the markdown parser', function () {
        expect(config('squidink.default_parser'))->toBe('markdown');
    });

    it('exposes a schema section for nodes and marks', function () {
        expect(config('squidink.schema'))
            ->toHaveKeys(['nodes', 'marks']);
    });

    it('has no image resolver until one is registered', function () {
        expect(config('squidink.image_resolver'))->toBeNull();
    });
});
