<?php

declare(strict_types=1);

use Marque\Trove\Registry\NavRegistry;

describe('disguise nav registration', function () {
    it('registers a Torrents entry', function () {
        $items = app(NavRegistry::class)->all();

        expect($items)->toHaveKey('disguise-torrents')
            ->and($items['disguise-torrents']->label)->toBe('Torrents')
            ->and($items['disguise-torrents']->route)->toBe('torrents.index');
    });

    // disguise is the PUBLIC frontend — guest browsing is the entire point, so
    // unlike guise its entry must show with nobody logged in.
    it('shows the entry to guests', function () {
        expect(app(NavRegistry::class)->visibleTo(null))->toHaveKey('disguise-torrents');
    });
});
