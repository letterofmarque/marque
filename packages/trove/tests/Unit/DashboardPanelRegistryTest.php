<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\MustVerifyEmail;
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

    // #11070 item 4: the dashboard admits unverified users, while guise's
    // torrent routes need `verified`. A panel showing torrents must not show
    // an unverified user what the routes would refuse them.
    describe('verifiedOnly()', function () {
        $verifiable = fn (bool $verified) => new class($verified) implements MustVerifyEmail
        {
            public function __construct(private bool $verified) {}

            public function hasVerifiedEmail(): bool
            {
                return $this->verified;
            }

            public function markEmailAsVerified(): bool
            {
                return true;
            }

            public function markEmailAsUnverified(): bool
            {
                return true;
            }

            public function sendEmailVerificationNotification(): void {}

            public function getEmailForVerification(): string
            {
                return 'x@example.com';
            }
        };

        it('admits a verified user and denies an unverified one', function () use ($verifiable) {
            $panel = DashboardPanel::verifiedOnly('latest', 'Latest', 'latest-panel');

            expect($panel->isVisibleTo($verifiable(true)))->toBeTrue()
                ->and($panel->isVisibleTo($verifiable(false)))->toBeFalse();
        });

        // The same answer Laravel's `verified` middleware gives: an app whose
        // user model has no verification at all lets everyone through.
        it('admits a user whose model has no email verification', function () {
            $panel = DashboardPanel::verifiedOnly('latest', 'Latest', 'latest-panel');

            expect($panel->isVisibleTo(new TestUser(['role' => Role::User->value])))->toBeTrue();
        });

        it('never admits a guest', function () {
            expect(DashboardPanel::verifiedOnly('latest', 'Latest', 'latest-panel')->isVisibleTo(null))->toBeFalse();
        });

        it('applies its own visibility only once the user is verified', function () use ($verifiable) {
            $asked = 0;
            $panel = DashboardPanel::verifiedOnly('latest', 'Latest', 'latest-panel', visible: function (?object $user) use (&$asked): bool {
                $asked++;

                return false;
            });

            expect($panel->isVisibleTo($verifiable(false)))->toBeFalse()
                ->and($asked)->toBe(0)
                ->and($panel->isVisibleTo($verifiable(true)))->toBeFalse()
                ->and($asked)->toBe(1);
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
