<?php

declare(strict_types=1);

use Marque\Disguise\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('upload page requires authentication', function () {
    $this->get(route('torrents.upload'))
        ->assertRedirect(route('login'));
});

test('authorized user can access upload page', function () {
    $admin = TestUser::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('torrents.upload'))
        ->assertOk();
});

test('regular user cannot access upload page', function () {
    $this->actingAs($this->user)
        ->get(route('torrents.upload'))
        ->assertForbidden();
});

test('upload page returns 404 when uploads disabled', function () {
    config()->set('disguise.allow_upload', false);

    $admin = TestUser::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('torrents.upload'))
        ->assertNotFound();
});
