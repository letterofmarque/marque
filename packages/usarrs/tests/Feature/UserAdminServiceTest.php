<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Usarrs\Services\UserAdminService;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->service = new UserAdminService;
});

test('can list users', function () {
    TestUser::factory()->count(3)->create();

    $result = $this->service->list();

    expect($result->total())->toBe(3);
});

test('can search users by name', function () {
    TestUser::factory()->create(['name' => 'Alice Smith']);
    TestUser::factory()->create(['name' => 'Bob Jones']);

    $result = $this->service->list(search: 'alice');

    expect($result->total())->toBe(1)
        ->and($result->first()->name)->toBe('Alice Smith');
});

test('can filter users by role', function () {
    TestUser::factory()->create(['role' => Role::User->value]);
    TestUser::factory()->admin()->create();

    $result = $this->service->list(role: Role::Admin->value);

    expect($result->total())->toBe(1);
});

test('can ban a user', function () {
    $user = TestUser::factory()->create();

    $this->service->ban($user);

    expect($user->fresh()->status)->toBe('banned');
});

test('can unban a user', function () {
    $user = TestUser::factory()->banned()->create();

    $this->service->unban($user);

    expect($user->fresh()->status)->toBe('active');
});

test('can change user role', function () {
    $user = TestUser::factory()->create();

    $this->service->setRole($user, Role::Moderator);

    expect($user->fresh()->role)->toBe(Role::Moderator);
});
