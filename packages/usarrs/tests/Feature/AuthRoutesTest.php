<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('login page is accessible', function () {
    $this->get(route('login'))
        ->assertOk();
});

test('register page is accessible', function () {
    $this->get(route('register'))
        ->assertOk();
});

test('register page returns 404 for invite_only driver', function () {
    config()->set('usarrs.auth_driver', 'invite_only');

    $this->get(route('register'))
        ->assertNotFound();
});

test('forgot password page is accessible', function () {
    $this->get(route('password.request'))
        ->assertOk();
});

test('logout requires authentication', function () {
    $this->post(route('logout'))
        ->assertRedirect();
});

test('authenticated user can logout', function () {
    $this->actingAs($this->user)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('login authenticates user with valid credentials', function () {
    $user = TestUser::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@example.com')
        ->set('password', 'password123')
        ->call('login')
        ->assertRedirect('/');
});

test('login fails with invalid credentials', function () {
    Livewire::test(Login::class)
        ->set('email', 'test@example.com')
        ->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors('email');
});
