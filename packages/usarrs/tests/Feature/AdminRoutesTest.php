<?php

declare(strict_types=1);

use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->admin = TestUser::factory()->admin()->create();
    $this->moderator = TestUser::factory()->moderator()->create();
});

test('admin user index requires authentication', function () {
    $this->get(route('admin.users.index'))
        ->assertRedirect();
});

test('regular user cannot access admin', function () {
    $this->actingAs($this->user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('moderator can access user index', function () {
    $this->actingAs($this->moderator)
        ->get(route('admin.users.index'))
        ->assertOk();
});

test('admin can access user index', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users.index'))
        ->assertOk();
});

test('admin returns 404 when disabled', function () {
    config()->set('usarrs.admin.enabled', false);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index'))
        ->assertNotFound();
});
