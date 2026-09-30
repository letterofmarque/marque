<?php

declare(strict_types=1);

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
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

// Build #124 CP #737: connecting a provider redirects here with a flashed
// result. The page used to render neither, so "GitHub connected" was set and
// never seen.
test('profile shows a flashed status and flashed errors', function () {
    $user = TestUser::factory()->create();

    $this->actingAs($user)
        ->withSession(['status' => 'Github connected.'])
        ->get(route('profile.show'))
        ->assertSee('Github connected.');

    $this->actingAs($user)
        ->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['email' => 'Already connected elsewhere.']))])
        ->get(route('profile.show'))
        ->assertSee('Already connected elsewhere.');
});
