<?php

declare(strict_types=1);

use Marque\Deck\View\Components\Navigation;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;

describe('Navigation Component', function () {
    it('can be resolved from container', function () {
        $component = $this->app->make(Navigation::class);

        expect($component)->toBeInstanceOf(Navigation::class);
    });

    it('uses app name from config', function () {
        config()->set('deck.app_name', 'TestTracker');

        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect($component->appName)->toBe('TestTracker');
    });

    // A deployment with only deck installed. Nothing has registered, so the nav
    // is empty — not an error, and not a link to a route that does not exist.
    it('renders an empty nav when nothing has registered', function () {
        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect($component->items)->toBeEmpty();
    });

    it('renders whatever is in the registry', function () {
        app(NavRegistry::class)->register(new NavItem('torrents', 'Torrents', 'torrents.index'));

        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect($component->items)->toHaveCount(1)
            ->and($component->items[0]['label'])->toBe('Torrents')
            ->and($component->items[0]['route'])->toBe('torrents.index');
    });

    // The whole point of the Checkpoint: the shell no longer knows what a Marque
    // package is, so a package we have never heard of gets a nav entry.
    it('renders an entry from a package that is not ours', function () {
        app(NavRegistry::class)->register(
            new NavItem('acme-stats', 'Stats', 'acme.stats.index'),
        );

        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect($component->items)->toHaveCount(1)
            ->and($component->items[0]['identifier'])->toBe('acme-stats');
    });

    it('orders items by the position the registering package chose', function () {
        $registry = app(NavRegistry::class);
        $registry->register(new NavItem('third', 'Third', 'c.index', position: 30));
        $registry->register(new NavItem('first', 'First', 'a.index', position: 10));
        $registry->register(new NavItem('second', 'Second', 'b.index', position: 20));

        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect(array_column($component->items, 'label'))
            ->toBe(['First', 'Second', 'Third']);
    });

    it('hides an item whose visibility rule rejects the current user', function () {
        $registry = app(NavRegistry::class);
        $registry->register(new NavItem('public', 'Public', 'p.index'));
        $registry->register(new NavItem('hidden', 'Hidden', 'h.index', visible: fn () => false));

        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect($component->items)->toHaveCount(1)
            ->and($component->items[0]['label'])->toBe('Public');
    });

    // The dangling admin.index link this Checkpoint exists to kill: the old
    // component rendered it whenever an admin was logged in, whether or not
    // anything had registered the route.
    it('does not invent an admin entry', function () {
        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect(array_column($component->items, 'route'))
            ->not->toContain('admin.index');
    });
});

describe('Navigation independence', function () {
    it('names no Marque package', function () {
        $source = file_get_contents(
            __DIR__.'/../../src/View/Components/Navigation.php',
        );

        // Its own namespace and the trove registry it reads are allowed;
        // naming a *consumer* is what went wrong before.
        foreach (['Guise', 'Disguise', 'Usarrs', 'Parley', 'Squidink', 'Taxonomy', 'Skipper'] as $package) {
            expect($source)->not->toContain("Marque\\{$package}");
        }
    });
});
