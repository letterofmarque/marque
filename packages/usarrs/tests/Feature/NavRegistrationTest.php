<?php

declare(strict_types=1);

use Marque\Trove\Registry\NavRegistry;
use Marque\Usarrs\Tests\TestUser;

describe('usarrs nav registration', function () {
    it('registers a Profile entry', function () {
        $items = app(NavRegistry::class)->all();

        expect($items)->toHaveKey('usarrs-profile')
            ->and($items['usarrs-profile']->label)->toBe('Profile')
            ->and($items['usarrs-profile']->route)->toBe('profile.show');
    });

    // Spec #118 / Build #108 CP6: the dashboard is reachable from the shell,
    // not only by typing the URL, and it is the first thing a signed-in user
    // is offered.
    it('registers a Dashboard entry pointing at a route it owns', function () {
        $items = app(NavRegistry::class)->all();

        expect($items)->toHaveKey('usarrs-dashboard')
            ->and($items['usarrs-dashboard']->label)->toBe('Dashboard')
            ->and($items['usarrs-dashboard']->route)->toBe('dashboard.index')
            ->and(route($items['usarrs-dashboard']->route))->toBeString();
    });

    it('shows Dashboard to an authenticated user and hides it from guests', function () {
        $user = TestUser::factory()->create();
        $registry = app(NavRegistry::class);

        expect($registry->visibleTo($user))->toHaveKey('usarrs-dashboard')
            ->and($registry->visibleTo(null))->not->toHaveKey('usarrs-dashboard');
    });

    it('orders Dashboard ahead of every other usarrs entry', function () {
        $keys = array_keys(app(NavRegistry::class)->all());

        expect(array_search('usarrs-dashboard', $keys, true))
            ->toBeLessThan(array_search('usarrs-profile', $keys, true));
    });

    it('hides Profile from guests', function () {
        expect(app(NavRegistry::class)->visibleTo(null))->not->toHaveKey('usarrs-profile');
    });

    it('shows Profile to an authenticated user', function () {
        $user = TestUser::factory()->create();

        expect(app(NavRegistry::class)->visibleTo($user))->toHaveKey('usarrs-profile');
    });

    // usarrs owns admin/users, so it registers the nav entry for its own screen.
    // The old shell invented an `admin.index` link regardless of whether any
    // package had registered that route — see CP #588.
    it('registers an admin entry pointing at a route it owns', function () {
        $items = app(NavRegistry::class)->all();

        expect($items)->toHaveKey('usarrs-admin')
            ->and($items['usarrs-admin']->route)->toBe('admin.users.index')
            ->and(route($items['usarrs-admin']->route))->toBeString();
    });

    it('shows the admin entry only to an admin', function () {
        $admin = TestUser::factory()->create(['role' => 'admin']);
        $mod = TestUser::factory()->create(['role' => 'moderator']);
        $plain = TestUser::factory()->create(['role' => 'user']);

        $registry = app(NavRegistry::class);

        expect($registry->visibleTo($admin))->toHaveKey('usarrs-admin')
            ->and($registry->visibleTo($mod))->not->toHaveKey('usarrs-admin')
            ->and($registry->visibleTo($plain))->not->toHaveKey('usarrs-admin')
            ->and($registry->visibleTo(null))->not->toHaveKey('usarrs-admin');
    });
});
