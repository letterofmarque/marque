<?php

declare(strict_types=1);

use Marque\Skipper\Livewire\Panel;
use Marque\Skipper\Tests\TestUser;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;

describe('the admin panel', function () {
    it('registers a real admin.index route', function () {
        expect(app('router')->has('admin.index'))->toBeTrue();
    });

    it('renders the panel for an admin', function () {
        $admin = TestUser::factory()->create(['role' => Role::Admin->value]);

        $this->actingAs($admin)->get(route('admin.index'))->assertOk();
    });

    // A fresh install has installed skipper and nothing else. An empty panel is
    // the correct state, and it must not error.
    it('renders with zero registered screens', function () {
        $admin = TestUser::factory()->create(['role' => Role::Admin->value]);

        $this->actingAs($admin)->get(route('admin.index'))->assertOk();
    });

    it('lists registered screens', function () {
        app(AdminScreenRegistry::class)->register(new AdminScreen(
            identifier: 'users',
            label: 'Manage Users',
            component: 'vendor-user-index',
            path: 'admin/users',
        ));

        $admin = TestUser::factory()->create(['role' => Role::Admin->value]);

        $this->actingAs($admin)->get(route('admin.index'))->assertSee('Manage Users');
    });

    it('groups screens and orders them deterministically', function () {
        $registry = app(AdminScreenRegistry::class);
        $registry->register(new AdminScreen('b', 'Beta', 'c', 'admin/b', group: 'Content', position: 20));
        $registry->register(new AdminScreen('a', 'Alpha', 'c', 'admin/a', group: 'Content', position: 10));
        $registry->register(new AdminScreen('z', 'Zulu', 'c', 'admin/z', group: 'People', position: 5));

        $this->actingAs(TestUser::factory()->create(['role' => Role::Admin->value]));

        $panel = new Panel;
        $panel->mount();

        expect(array_keys($panel->groups))->toBe(['Content', 'People'])
            ->and(array_column($panel->groups['Content'], 'label'))->toBe(['Alpha', 'Beta']);
    });

    // A screen with no group still has to land somewhere sensible rather than
    // vanishing or producing a null array key.
    it('files an ungrouped screen under a default heading', function () {
        app(AdminScreenRegistry::class)->register(
            new AdminScreen('loose', 'Loose Screen', 'c', 'admin/loose'),
        );

        $this->actingAs(TestUser::factory()->create(['role' => Role::Admin->value]));

        $panel = new Panel;
        $panel->mount();

        expect($panel->groups)->toHaveCount(1)
            ->and(array_column(reset($panel->groups), 'label'))->toBe(['Loose Screen']);
    });

    // The panel's own listing is filtered by role. CP6 adds the second half —
    // enforcement at route resolution, so a hidden screen is also unreachable.
    it('hides screens above the viewer role from the listing', function () {
        $registry = app(AdminScreenRegistry::class);
        $registry->register(new AdminScreen('admin-only', 'Admin Only', 'c', 'admin/x', Role::Admin));
        $registry->register(new AdminScreen('mod-ok', 'Mod OK', 'c', 'admin/y', Role::Moderator));

        $mod = TestUser::factory()->create(['role' => Role::Moderator->value]);

        $this->actingAs($mod)->get(route('admin.index'))
            ->assertSee('Mod OK')
            ->assertDontSee('Admin Only');
    });

    it('redirects a guest to login rather than showing the panel', function () {
        $this->get(route('admin.index'))->assertRedirect(route('login'));
    });

    // A package can register a screen and forget to bind its route. That is the
    // package's bug, but it must not take the whole panel down with it.
    it('skips a screen whose route is not bound rather than erroring', function () {
        $registry = app(AdminScreenRegistry::class);
        $registry->register(new AdminScreen('real', 'Real Screen', 'c', 'admin/real'));
        $registry->register(new AdminScreen('broken', 'Broken Screen', 'c', 'admin/broken'));

        $admin = TestUser::factory()->create(['role' => Role::Admin->value]);

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Real Screen')
            ->assertDontSee('Broken Screen');
    });
});
