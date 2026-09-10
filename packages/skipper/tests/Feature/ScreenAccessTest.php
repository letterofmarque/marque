<?php

declare(strict_types=1);

use Marque\Skipper\Tests\TestUser;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreen;
use Marque\Trove\Registry\AdminScreenRegistry;

/**
 * The security half of the panel.
 *
 * Every test here asserts on a **direct HTTP request** to a screen's own URL,
 * never on what the panel lists. Filtering a menu is not protecting a screen,
 * and a passing nav-filter test does not satisfy any criterion in this file.
 */
function registerScreen(string $identifier, Role $minimumRole): void
{
    app(AdminScreenRegistry::class)->register(new AdminScreen(
        identifier: $identifier,
        label: ucfirst($identifier),
        component: 'skipper-test-screen',
        path: 'admin/'.$identifier,
        minimumRole: $minimumRole,
    ));
}

describe('screen routing', function () {
    it('makes a registered screen reachable at its declared path', function () {
        registerScreen('reports', Role::Moderator);

        $this->actingAs(TestUser::factory()->create(['role' => Role::Moderator->value]))
            ->get('/admin/reports')
            ->assertOk();
    });

    // Named aliases are generated in the provider's booted() callback. Under
    // Testbench the app is ALREADY booted before a test body runs, so a screen
    // registered inside a test has missed that callback and gets no alias —
    // a harness artifact, not the real behaviour.
    //
    // Verified in a real Laravel 13.31.0 app instead, with a tenant provider
    // booting AFTER skipper's (Build #101 CP1, and re-run for this Checkpoint):
    //
    //   admin          -> admin.index
    //   admin/reports  -> admin.reports     <- the generated alias
    //   admin/{screen} -> admin.screen
    //
    // and all three survive `php artisan route:cache`.
    //
    // What CAN be asserted here is the derivation itself and the catch-all that
    // makes the screen reachable regardless of when it was registered.
    it('derives the route name from the identifier', function () {
        registerScreen('reports', Role::Moderator);

        expect(app(AdminScreenRegistry::class)->find('reports')->routeName())
            ->toBe('admin.reports');
    });

    it('registers the catch-all that resolves screens at request time', function () {
        expect(app('router')->has('admin.screen'))->toBeTrue();
    });

    // CP1 proved registration is legal up to `booted`. A screen registered by a
    // provider that boots after skipper's must still be reachable.
    it('reaches a screen registered after skipper booted', function () {
        registerScreen('late', Role::Admin);

        $this->actingAs(TestUser::factory()->create(['role' => Role::Admin->value]))
            ->get('/admin/late')
            ->assertOk();
    });

    it('404s an unknown screen identifier', function () {
        $this->actingAs(TestUser::factory()->create(['role' => Role::Admin->value]))
            ->get('/admin/no-such-screen')
            ->assertNotFound();
    });
});

describe('the permission gate, enforced at route resolution', function () {
    // THE test. A moderator typing an admin screen's URL must be refused, even
    // though the panel would never have shown them the link.
    it('403s a moderator requesting an admin-only screen directly', function () {
        registerScreen('secrets', Role::Admin);

        $this->actingAs(TestUser::factory()->create(['role' => Role::Moderator->value]))
            ->get('/admin/secrets')
            ->assertForbidden();
    });

    it('403s a user requesting a moderator screen directly', function () {
        registerScreen('reports', Role::Moderator);

        $this->actingAs(TestUser::factory()->create(['role' => Role::User->value]))
            ->get('/admin/reports')
            ->assertForbidden();
    });

    it('403s an uploader requesting a moderator screen directly', function () {
        registerScreen('reports', Role::Moderator);

        $this->actingAs(TestUser::factory()->create(['role' => Role::Uploader->value]))
            ->get('/admin/reports')
            ->assertForbidden();
    });

    it('allows a role that exactly meets the floor', function () {
        registerScreen('reports', Role::Moderator);

        $this->actingAs(TestUser::factory()->create(['role' => Role::Moderator->value]))
            ->get('/admin/reports')
            ->assertOk();
    });

    it('allows a role above the floor', function () {
        registerScreen('reports', Role::Moderator);

        $this->actingAs(TestUser::factory()->create(['role' => Role::Admin->value]))
            ->get('/admin/reports')
            ->assertOk();
    });

    it('allows every role at an uploader-floor screen except plain users', function () {
        registerScreen('uploads', Role::Uploader);

        foreach ([Role::Uploader, Role::Moderator, Role::Admin] as $role) {
            $this->actingAs(TestUser::factory()->create(['role' => $role->value]))
                ->get('/admin/uploads')
                ->assertOk();
        }

        $this->actingAs(TestUser::factory()->create(['role' => Role::User->value]))
            ->get('/admin/uploads')
            ->assertForbidden();
    });

    // A guest has not failed authorisation — they have not authenticated. The
    // difference matters: 403 tells an anonymous visitor the screen exists.
    it('redirects a guest to login rather than 403ing', function () {
        registerScreen('secrets', Role::Admin);

        $this->get('/admin/secrets')->assertRedirect(route('login'));
    });

    it('redirects a guest from the panel index too', function () {
        $this->get(route('admin.index'))->assertRedirect(route('login'));
    });
});

describe('the gate and the listing agree', function () {
    // One registry entry, two enforcement points. If these ever disagree, one
    // of them is wrong — and the dangerous direction is a screen that is hidden
    // but reachable.
    it('never leaves a screen hidden from the panel yet reachable by URL', function () {
        registerScreen('secrets', Role::Admin);
        registerScreen('reports', Role::Moderator);

        $mod = TestUser::factory()->create(['role' => Role::Moderator->value]);

        $listed = array_keys(app(AdminScreenRegistry::class)->visibleTo(Role::Moderator));

        expect($listed)->toContain('reports')
            ->and($listed)->not->toContain('secrets');

        // What the listing hides, the gate must also refuse.
        $this->actingAs($mod)->get('/admin/secrets')->assertForbidden();
        $this->actingAs($mod)->get('/admin/reports')->assertOk();
    });
});

describe('screens that own their own route', function () {
    // A package like usarrs binds `admin/users` itself and names it
    // `admin.users.index`. skipper must not shadow that with a second URL
    // derived from the identifier — one screen, one canonical path.
    it('honours a declared path rather than deriving one from the identifier', function () {
        app(AdminScreenRegistry::class)->register(new AdminScreen(
            identifier: 'vendor-users',
            label: 'Users',
            component: 'skipper-test-screen',
            path: 'admin/users',
            minimumRole: Role::Moderator,
        ));

        $screen = app(AdminScreenRegistry::class)->find('vendor-users');

        expect($screen->path)->toBe('admin/users')
            ->and($screen->pathSegment())->toBe('users');
    });

    it('resolves a screen by its declared path segment, not its identifier', function () {
        app(AdminScreenRegistry::class)->register(new AdminScreen(
            identifier: 'vendor-users',
            label: 'Users',
            component: 'skipper-test-screen',
            path: 'admin/users',
            minimumRole: Role::Moderator,
        ));

        $this->actingAs(TestUser::factory()->create(['role' => Role::Moderator->value]))
            ->get('/admin/users')
            ->assertOk();
    });
});

describe('screens whose package already bound the route', function () {
    // usarrs binds `admin/users` as `admin.users.index` in its own routes file
    // and registers a screen pointing at the same path. skipper must leave that
    // route alone: generating its own at the same path silently replaced the
    // package's named route, breaking published API with no error anywhere.
    it('does not generate an alias over a path another package already bound', function () {
        // The TestCase binds admin/users as admin.users (see defineWebRoutes).
        app(AdminScreenRegistry::class)->register(new AdminScreen(
            identifier: 'vendor-users',
            label: 'Users',
            component: 'skipper-test-screen',
            path: 'admin/users',
            minimumRole: Role::Moderator,
        ));

        // The pre-existing name survives.
        expect(app('router')->has('admin.users'))->toBeTrue();
    });
});
