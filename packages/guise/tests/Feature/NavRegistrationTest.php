<?php

declare(strict_types=1);

use Marque\Guise\Tests\TestUser;
use Marque\Trove\Registry\NavRegistry;

describe('guise nav registration', function () {
    it('registers a Torrents entry', function () {
        $items = app(NavRegistry::class)->all();

        expect($items)->toHaveKey('guise-torrents')
            ->and($items['guise-torrents']->label)->toBe('Torrents')
            ->and($items['guise-torrents']->route)->toBe('torrents.index');
    });

    // guise is the private frontend — its listing is behind auth, so the entry
    // has no business showing to a guest.
    it('hides the entry from guests', function () {
        expect(app(NavRegistry::class)->visibleTo(null))->not->toHaveKey('guise-torrents');
    });

    it('shows the entry to an authenticated user', function () {
        $user = TestUser::factory()->create();

        expect(app(NavRegistry::class)->visibleTo($user))->toHaveKey('guise-torrents');
    });
});
