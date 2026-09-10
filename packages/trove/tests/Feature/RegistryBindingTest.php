<?php

declare(strict_types=1);

use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;

describe('registry container bindings', function () {
    it('binds AdminScreenRegistry as a singleton', function () {
        expect(app(AdminScreenRegistry::class))
            ->toBe(app(AdminScreenRegistry::class));
    });

    it('binds NavRegistry as a singleton', function () {
        expect(app(NavRegistry::class))->toBe(app(NavRegistry::class));
    });

    // The property every tenant depends on: a package registers in its own
    // provider, and whatever resolves the registry later sees it.
    it('keeps registrations made through the container', function () {
        app(NavRegistry::class)->register(new NavItem('probe', 'Probe', 'probe.index'));

        expect(app(NavRegistry::class)->all())->toHaveKey('probe');
    });
});
