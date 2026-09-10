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
