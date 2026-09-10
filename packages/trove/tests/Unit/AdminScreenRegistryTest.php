<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;

function screen(string $identifier = 'users', ...$overrides): AdminScreen
{
    return new AdminScreen(
        identifier: $identifier,
        label: $overrides['label'] ?? 'Users',
        component: $overrides['component'] ?? 'Vendor\\Livewire\\UserIndex',
        path: $overrides['path'] ?? 'admin/'.$identifier,
        minimumRole: $overrides['minimumRole'] ?? Role::Admin,
        icon: $overrides['icon'] ?? 'user',
        group: $overrides['group'] ?? 'Users',
        position: $overrides['position'] ?? 100,
    );
}

describe('AdminScreenRegistry', function () {
    it('registers and retrieves a screen by identifier', function () {
        $registry = new AdminScreenRegistry;
        $registry->register(screen('users'));

        expect($registry->find('users'))->not->toBeNull()
            ->and($registry->find('users')->identifier)->toBe('users');
    });

    it('returns null for an unknown identifier', function () {
        expect((new AdminScreenRegistry)->find('nope'))->toBeNull();
    });

    // The property CP1 proved skipper depends on: the route table is built from
    // this without any user in scope.
    it('enumerates every screen without a user', function () {
        $registry = new AdminScreenRegistry;
        $registry->register(screen('users'));
        $registry->register(screen('taxonomy'));

        expect($registry->all())->toHaveCount(2)
            ->and(array_keys($registry->all()))->toBe(['users', 'taxonomy']);
    });

    it('orders screens by position then label', function () {
        $registry = new AdminScreenRegistry;
        $registry->register(screen('zebra', label: 'Zebra', position: 10));
        $registry->register(screen('apple', label: 'Apple', position: 50));
        $registry->register(screen('mango', label: 'Mango', position: 10));

        expect(array_keys($registry->all()))->toBe(['mango', 'zebra', 'apple']);
    });

    it('derives a route name from the identifier', function () {
        expect(screen('taxonomy')->routeName())->toBe('admin.taxonomy');
    });

    it('filters to the screens a role may see', function () {
        $registry = new AdminScreenRegistry;
        $registry->register(screen('users', minimumRole: Role::Admin));
        $registry->register(screen('reports', minimumRole: Role::Moderator));
        $registry->register(screen('uploads', minimumRole: Role::Uploader));

        expect(array_keys($registry->visibleTo(Role::Moderator)))
            ->toBe(['reports', 'uploads']);
    });

    it('shows every screen to an admin', function () {
        $registry = new AdminScreenRegistry;
        $registry->register(screen('users', minimumRole: Role::Admin));
        $registry->register(screen('reports', minimumRole: Role::Moderator));

        expect($registry->visibleTo(Role::Admin))->toHaveCount(2);
    });

    it('shows no admin screens to a plain user', function () {
        $registry = new AdminScreenRegistry;
        $registry->register(screen('users', minimumRole: Role::Admin));

        expect($registry->visibleTo(Role::User))->toBe([]);
    });

    // The gate skipper enforces at route resolution — the same source the nav
    // filter reads, so the two cannot drift.
    it('answers whether a role may access a specific screen', function () {
        $registry = new AdminScreenRegistry;
        $registry->register(screen('users', minimumRole: Role::Admin));

        expect($registry->allows('users', Role::Admin))->toBeTrue()
            ->and($registry->allows('users', Role::Moderator))->toBeFalse();
    });

    it('denies access to a screen that does not exist', function () {
        expect((new AdminScreenRegistry)->allows('ghost', Role::Admin))->toBeFalse();
    });

    // Silent last-write-wins is the failure mode squidink's shortcode registry
    // was flagged for in job #10546. Refuse instead.
    it('refuses a duplicate identifier', function () {
        $registry = new AdminScreenRegistry;
        $registry->register(screen('users'));

        $registry->register(screen('users'));
    })->throws(InvalidArgumentException::class, 'users');

    it('rejects an empty identifier', function () {
        (new AdminScreenRegistry)->register(screen(''));
    })->throws(InvalidArgumentException::class);
});
