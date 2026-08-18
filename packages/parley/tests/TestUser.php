<?php

declare(strict_types=1);

namespace Marque\Parley\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;

/**
 * The host app's user model, as far as parley is concerned.
 *
 * Parley never ships a user model — trove's config points at whatever the host
 * uses. This stands in for it under test.
 */
class TestUser extends Authenticatable implements UserInterface
{
    use HasFactory;
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    protected $attributes = [
        'role' => 'user',
        'status' => 'active',
    ];

    public function generatePasskey(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected static function newFactory(): Factory
    {
        return TestUserFactory::new();
    }
}

class TestUserFactory extends Factory
{
    protected $model = TestUser::class;

    public function definition(): array
    {
        return [
            'name' => 'Test User',
            'email' => 'user'.uniqid().'@example.test',
            'password' => bcrypt('password'),
            'role' => Role::User->value,
            'status' => 'active',
        ];
    }

    public function moderator(): static
    {
        return $this->state(fn (): array => ['role' => Role::Moderator->value]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => Role::Admin->value]);
    }
}
