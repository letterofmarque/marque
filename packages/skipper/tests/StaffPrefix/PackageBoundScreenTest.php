<?php

declare(strict_types=1);

// #11108, from the read-through of the fix: with a non-default prefix, skipper
// generated staff/owned for a screen whose package binds admin/owned, and
// served it without the package's middleware. And by its identifier, the
// catch-all served it under any prefix.

use Marque\Skipper\Tests\TestUser;
use Marque\Trove\Enums\Role;

it('generates no route of its own for a screen whose package binds its path', function () {
    expect(app('router')->has('admin.vendor-owned'))->toBeFalse();
});

it('refuses to serve that screen at the prefixed path or by identifier', function () {
    $admin = TestUser::factory()->create(['role' => Role::Admin->value]);

    $this->actingAs($admin)->get('/staff/owned')->assertNotFound();
    $this->actingAs($admin)->get('/staff/vendor-owned')->assertNotFound();
});

it('leaves the package\'s own route to answer, and the panel links to it', function () {
    $admin = TestUser::factory()->create(['role' => Role::Admin->value]);

    $this->actingAs($admin)->get('/admin/owned')->assertOk()->assertSee('package own route');
    $this->actingAs($admin)->get('/staff')->assertOk()->assertSee(url('/admin/owned'), false);
});
