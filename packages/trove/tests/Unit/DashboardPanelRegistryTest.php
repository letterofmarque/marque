<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\DashboardPanel;
use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Trove\Tests\TestUser;

describe('DashboardPanelRegistry', function () {
    it('registers and enumerates panels', function () {
        $registry = new DashboardPanelRegistry;
        $registry->register(new DashboardPanel('ratio', 'Ratio', 'bloodhound-ratio-panel'));

        expect($registry->all())->toHaveCount(1)
            ->and($registry->all()['ratio']->label)->toBe('Ratio');
    });

    it('orders panels by position then label', function () {
        $registry = new DashboardPanelRegistry;
        $registry->register(new DashboardPanel('zebra', 'Zebra', 'z-panel', position: 10));
        $registry->register(new DashboardPanel('apple', 'Apple', 'a-panel', position: 50));
        $registry->register(new DashboardPanel('mango', 'Mango', 'm-panel', position: 10));

        expect(array_keys($registry->all()))->toBe(['mango', 'zebra', 'apple']);
    });

    // A silently-overwritten panel is a package whose contribution vanished
    // with no error anywhere — the exact failure the registries exist to stop.
    it('refuses a duplicate identifier', function () {
        $registry = new DashboardPanelRegistry;
        $registry->register(new DashboardPanel('ratio', 'Ratio', 'ratio-panel'));

        $registry->register(new DashboardPanel('ratio', 'Other', 'other-panel'));
    })->throws(InvalidArgumentException::class, 'ratio');

    it('finds a panel by identifier, or returns null', function () {
        $registry = new DashboardPanelRegistry;
        $registry->register(new DashboardPanel('ratio', 'Ratio', 'ratio-panel'));

        expect($registry->find('ratio')?->component)->toBe('ratio-panel')
            ->and($registry->find('nope'))->toBeNull();
    });

    it('shows a panel with no visibility rule to everyone', function () {
        $registry = new DashboardPanelRegistry;
        $registry->register(new DashboardPanel('security', 'Security', 'security-panel'));

        expect($registry->visibleTo(null))->toHaveCount(1);
    });

    // The per-request half of the three mechanisms. "Only if this user has
    // invites" is a query, not a rank comparison — the same reason NavItem
    // takes a closure rather than a role.
    it('evaluates an arbitrary visibility closure against the user', function () {
        $registry = new DashboardPanelRegistry;
        $registry->register(new DashboardPanel(
            'invites',
            'Invites',
            'invites-panel',
            visible: fn (?object $user): bool => $user !== null && $user->name === 'has-invites',
        ));

        $with = new TestUser(['name' => 'has-invites']);
        $without = new TestUser(['name' => 'no-invites']);

        expect($registry->visibleTo($with))->toHaveCount(1)
            ->and($registry->visibleTo($without))->toHaveCount(0);
    });
});

describe('DashboardPanel', function () {
    it('rejects an empty identifier', function () {
        new DashboardPanel('', 'Ratio', 'ratio-panel');
    })->throws(InvalidArgumentException::class);

    it('rejects an empty component', function () {
        // A panel that names nothing to render is a page section that silently
        // produces no output — worse than a loud failure at registration.
        new DashboardPanel('ratio', 'Ratio', '');
    })->throws(InvalidArgumentException::class);

    describe('forRole()', function () {
        it('admits a user at or above the minimum role', function () {
            $panel = DashboardPanel::forRole('mod', 'Mod', 'mod-panel', Role::Moderator);

            expect($panel->isVisibleTo(new TestUser(['role' => Role::Admin->value])))->toBeTrue()
                ->and($panel->isVisibleTo(new TestUser(['role' => Role::Moderator->value])))->toBeTrue();
        });

        it('denies a user below the minimum role', function () {
            $panel = DashboardPanel::forRole('mod', 'Mod', 'mod-panel', Role::Moderator);

            expect($panel->isVisibleTo(new TestUser(['role' => Role::User->value])))->toBeFalse();
        });

        // Matching NavItem::forRole() exactly, so the two registries cannot
        // disagree about what a guest sees.
        it('never admits a guest', function () {
            $panel = DashboardPanel::forRole('mod', 'Mod', 'mod-panel', Role::User);

            expect($panel->isVisibleTo(null))->toBeFalse();
        });

        it('denies an object that cannot answer the role question', function () {
            $panel = DashboardPanel::forRole('mod', 'Mod', 'mod-panel', Role::User);

            expect($panel->isVisibleTo(new stdClass))->toBeFalse();
        });
    });
});

describe('container binding', function () {
    // A fresh instance per resolution would lose every registration: packages
    // register in boot() and the dashboard reads it back on a later request.
    // This is the one wiring mistake that produces an always-empty dashboard
    // with no error anywhere.
    it('is a singleton, so registrations survive resolution', function () {
        app(DashboardPanelRegistry::class)->register(
            new DashboardPanel('ratio', 'Ratio', 'ratio-panel')
        );

        expect(app(DashboardPanelRegistry::class)->find('ratio'))->not->toBeNull();
    });
});
