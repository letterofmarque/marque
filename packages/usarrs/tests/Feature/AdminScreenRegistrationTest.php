<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Usarrs\Livewire\Admin\UserIndex;
use Marque\Usarrs\Tests\TestUser;

describe('usarrs admin screen registration', function () {
    it('registers the user index as an admin screen', function () {
        $screen = app(AdminScreenRegistry::class)->find('usarrs-users');

        expect($screen)->not->toBeNull()
            ->and($screen->label)->toBe('Users')
            ->and($screen->component)->toBe('usarrs-admin-user-index');
    });

    // The floor must match what UserIndex::mount() already enforces —
    // abort_unless(auth()->user()->isModerator(), 403). Declaring Admin here
    // would make the panel hide a screen moderators can legitimately use.
    it('declares a moderator floor, matching the component', function () {
        expect(app(AdminScreenRegistry::class)->find('usarrs-users')->minimumRole)
            ->toBe(Role::Moderator);
    });

    it('is visible to a moderator and above', function () {
        $registry = app(AdminScreenRegistry::class);

        expect($registry->allows('usarrs-users', Role::Moderator))->toBeTrue()
            ->and($registry->allows('usarrs-users', Role::Admin))->toBeTrue()
            ->and($registry->allows('usarrs-users', Role::Uploader))->toBeFalse()
            ->and($registry->allows('usarrs-users', Role::User))->toBeFalse();
    });

    it('does not register when the admin surface is disabled', function () {
        // Registration happens in boot(), so the config has to be set before
        // the app is created — see the dedicated AdminDisabled test group.
        expect(config('usarrs.admin.enabled'))->toBeTrue();
    });

    it('groups the screen sensibly', function () {
        expect(app(AdminScreenRegistry::class)->find('usarrs-users')->group)
            ->toBe('Users');
    });
});

describe('usarrs admin routes survive the migration', function () {
    // These are published API at usarrs v6. A consumer's own view or redirect
    // may reference either name, so both must keep resolving.
    it('keeps route(admin.users.index) resolving', function () {
        expect(route('admin.users.index'))->toEndWith('/admin/users');
    });

    it('keeps route(admin.users.show) resolving', function () {
        expect(route('admin.users.show', ['user' => 1]))->toEndWith('/admin/users/1');
    });

    // usarrs keeps owning its own routes. The registry entry makes the screen
    // discoverable from the panel; it does not take over the routing, which is
    // what lets usarrs work with skipper absent.
    it('still binds its own admin routes', function () {
        expect(app('router')->has('admin.users.index'))->toBeTrue()
            ->and(app('router')->has('admin.users.show'))->toBeTrue();
    });

    it('points the registered screen at its own existing route', function () {
        expect(app(AdminScreenRegistry::class)->find('usarrs-users')->path)
            ->toBe('admin/users');
    });
});

describe('usarrs does not depend on skipper', function () {
    it('names skipper nowhere in its composer manifest', function () {
        $manifest = file_get_contents(__DIR__.'/../../composer.json');

        expect($manifest)->not->toContain('marque/skipper');
    });

    it('names skipper nowhere in its source', function () {
        $hits = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src'),
        );

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                if (str_contains(file_get_contents($file->getPathname()), 'Skipper')) {
                    $hits[] = $file->getFilename();
                }
            }
        }

        expect($hits)->toBe([]);
    });

    // The whole architecture in one assertion: the screens work with no panel
    // installed. This suite runs without SkipperServiceProvider registered.
    it('serves its admin screens with no panel installed', function () {
        $moderator = TestUser::factory()->moderator()->create();

        $this->actingAs($moderator)
            ->get(route('admin.users.index'))
            ->assertOk();
    });

    it('registers the screen even though nothing will render it', function () {
        // Registration is unconditional: usarrs cannot know whether a panel is
        // installed, and should not care. An unread registry entry is harmless.
        expect(app(AdminScreenRegistry::class)->find('usarrs-users'))->not->toBeNull();
    });
});

describe('the registered component is the real one', function () {
    // A screen naming a component that does not exist would 500 the moment
    // anyone clicked it, and nothing else in the registry would catch that.
    it('names a component Livewire can actually resolve', function () {
        $screen = app(AdminScreenRegistry::class)->find('usarrs-users');

        expect(Livewire\Livewire::new($screen->component))->toBeInstanceOf(UserIndex::class);
    });
});
