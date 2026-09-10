<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;
use Marque\Trove\Tests\TestUser;

describe('NavRegistry', function () {
    it('registers and enumerates items', function () {
        $registry = new NavRegistry;
        $registry->register(new NavItem('torrents', 'Torrents', 'torrents.index'));

        expect($registry->all())->toHaveCount(1)
            ->and($registry->all()['torrents']->label)->toBe('Torrents');
    });

    it('orders items by position then label', function () {
        $registry = new NavRegistry;
        $registry->register(new NavItem('zebra', 'Zebra', 'z.index', position: 10));
        $registry->register(new NavItem('apple', 'Apple', 'a.index', position: 50));
        $registry->register(new NavItem('mango', 'Mango', 'm.index', position: 10));

        expect(array_keys($registry->all()))->toBe(['mango', 'zebra', 'apple']);
    });

    it('refuses a duplicate identifier', function () {
        $registry = new NavRegistry;
        $registry->register(new NavItem('torrents', 'Torrents', 'torrents.index'));

        $registry->register(new NavItem('torrents', 'Other', 'other.index'));
    })->throws(InvalidArgumentException::class, 'torrents');

    it('shows an item with no visibility rule to everyone, guests included', function () {
        $registry = new NavRegistry;
        $registry->register(new NavItem('browse', 'Browse', 'browse.index'));

        expect($registry->visibleTo(null))->toHaveCount(1);
    });

    // This is why NavRegistry is not AdminScreenRegistry: the rule is arbitrary,
    // not a role. "Show Invites only if this user has any" must be expressible.
    it('evaluates an arbitrary visibility closure against the user', function () {
        $registry = new NavRegistry;
        $registry->register(new NavItem(
            'invites',
            'Invites',
            'invites.index',
            visible: fn (?object $user): bool => $user !== null && $user->name === 'has-invites',
        ));

        $withInvites = new TestUser(['name' => 'has-invites']);
        $without = new TestUser(['name' => 'no-invites']);

        expect($registry->visibleTo($withInvites))->toHaveCount(1)
            ->and($registry->visibleTo($without))->toBe([])
            ->and($registry->visibleTo(null))->toBe([]);
    });

    it('passes the actual user instance to the closure', function () {
        $seen = null;
        $registry = new NavRegistry;
        $registry->register(new NavItem(
            'probe',
            'Probe',
            'probe.index',
            visible: function (?object $user) use (&$seen): bool {
                $seen = $user;

                return true;
            },
        ));

        $user = new TestUser(['name' => 'alice']);
        $registry->visibleTo($user);

        expect($seen)->toBe($user);
    });

    // A role-based rule is just one kind of closure — the common case gets a
    // helper, but it is not a separate concept.
    it('supports a role-gated item via the role helper', function () {
        $registry = new NavRegistry;
        $registry->register(NavItem::forRole('admin', 'Admin', 'admin.index', Role::Admin));

        $admin = new TestUser(['role' => Role::Admin->value]);
        $mod = new TestUser(['role' => Role::Moderator->value]);

        expect($registry->visibleTo($admin))->toHaveCount(1)
            ->and($registry->visibleTo($mod))->toBe([])
            ->and($registry->visibleTo(null))->toBe([]);
    });

    it('keeps ordering when filtering by visibility', function () {
        $registry = new NavRegistry;
        $registry->register(new NavItem('c', 'C', 'c.index', position: 30));
        $registry->register(new NavItem('a', 'A', 'a.index', position: 10, visible: fn () => false));
        $registry->register(new NavItem('b', 'B', 'b.index', position: 20));

        expect(array_keys($registry->visibleTo(null)))->toBe(['b', 'c']);
    });

    it('rejects an empty identifier', function () {
        (new NavRegistry)->register(new NavItem('', 'Empty', 'x.index'));
    })->throws(InvalidArgumentException::class);
});
