<?php

declare(strict_types=1);

namespace Marque\Skipper\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;

/**
 * The host app's user model, as far as skipper is concerned.
 *
 * skipper ships no user model — trove's config points at whatever the host
 * uses. This stands in for it under test, and exists because every panel
 * authorisation decision reads a role off the authenticated user.
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

    public function generateAnnounceKey(): string
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
            'status' => 'active',
        ];
    }
}
