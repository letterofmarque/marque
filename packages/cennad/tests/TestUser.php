<?php

declare(strict_types=1);

namespace Marque\Cennad\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;

/**
 * Test user model for cennad tests.
 */
class TestUser extends Authenticatable implements UserInterface
{
    use HasFactory;
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $attributes = [
        'role' => 'user',
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => Role::User->value,
            'passkey' => bin2hex(random_bytes(16)),
        ];
    }

    public function uploader(): static
    {
        return $this->state(fn () => ['role' => Role::Uploader->value]);
    }

    public function moderator(): static
    {
        return $this->state(fn () => ['role' => Role::Moderator->value]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin->value]);
    }
}
