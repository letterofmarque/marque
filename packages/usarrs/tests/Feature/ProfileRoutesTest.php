<?php

declare(strict_types=1);

use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('profile page requires authentication', function () {
    $this->get(route('profile.show'))
        ->assertRedirect();
});

test('authenticated user can view profile', function () {
    $this->actingAs($this->user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee($this->user->name);
});

test('authenticated user can view edit page', function () {
    $this->actingAs($this->user)
        ->get(route('profile.edit'))
        ->assertOk();
});

test('authenticated user can view stats page', function () {
    $this->actingAs($this->user)
        ->get(route('profile.stats'))
        ->assertOk();
});
