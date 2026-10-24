<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\View;
use Marque\Trove\Registry\FooterItem;
use Marque\Trove\Registry\FooterItemRegistry;

// #11100: an app adds to the footer by registering with trove, and deck's own
// shipped layout renders it, with no view published or copied.
describe('footer items', function () {
    beforeEach(function () {
        View::addNamespace('probe', __DIR__.'/../views');
        $this->withoutVite();
    });

    function renderLayout(): string
    {
        return view('deck::layouts.app', ['slot' => 'content'])->render();
    }

    it('renders a registered item in the shipped layout', function () {
        app(FooterItemRegistry::class)->register(new FooterItem('build', 'probe::footer.build'));

        expect(renderLayout())->toContain('Build abc123')
            ->toContain('data-footer-item="build"');
    });

    it('orders items by position', function () {
        app(FooterItemRegistry::class)->register(new FooterItem('stats', 'probe::footer.stats', position: 20));
        app(FooterItemRegistry::class)->register(new FooterItem('rules', 'probe::footer.rules', position: 10));

        $html = renderLayout();

        expect(strpos($html, 'Rules'))->toBeLessThan(strpos($html, 'Stats'));
    });

    // Owner-only build info must not reach anyone else's page source at all.
    it('leaves out an item whose closure declines for this viewer, rather than hiding it', function () {
        $owner = (new User)->forceFill(['id' => 1]);
        $other = (new User)->forceFill(['id' => 2]);
        app(FooterItemRegistry::class)->register(new FooterItem(
            'build',
            'probe::footer.build',
            visible: fn (?object $user): bool => $user?->id === $owner->id,
        ));

        $this->actingAs($owner);
        expect(renderLayout())->toContain('Build abc123');

        $this->actingAs($other);
        expect(renderLayout())->not->toContain('Build abc123')
            ->not->toContain('data-footer-item="build"');
    });

    // From the independent /check of #11100: a guest is a viewer the closure
    // answers for (null), and a declined item must be absent from their page too.
    it('leaves out an item whose closure declines a guest', function () {
        app(FooterItemRegistry::class)->register(new FooterItem('build', 'probe::footer.build', visible: fn (?object $user): bool => $user !== null));
        app(FooterItemRegistry::class)->register(new FooterItem('rules', 'probe::footer.rules'));

        expect(renderLayout())->not->toContain('Build abc123')
            ->not->toContain('data-footer-item="build"')
            ->toContain('Rules');
    });

    it('shows an item with no visibility rule to a guest', function () {
        app(FooterItemRegistry::class)->register(new FooterItem('rules', 'probe::footer.rules'));

        expect(renderLayout())->toContain('Rules');
    });

    it('renders the footer as before when nothing is registered', function () {
        expect(renderLayout())->toContain('Powered by')
            ->not->toContain('data-footer-item');
    });
});
