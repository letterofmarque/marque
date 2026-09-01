<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\Role;

class TestUser extends Authenticatable implements UserInterface, PasskeyUser
{
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use TwoFactorAuthenticatable;
    use PasskeyAuthenticatable;

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

    /**
     * laravel/passkeys hardcodes 'user_id' on Passkey::user() but derives the
     * hasMany foreign key from the consumer's class name on
     * PasskeyAuthenticatable::passkeys() — the two only agree when the class
     * is named `User`. Overridden here so this fixture (named TestUser, for
     * clarity in test output) still resolves to the same column a real `User`
     * model would use. See the passkeys migration for the full explanation.
     */
    public function getForeignKey()
    {
        return 'user_id';
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
            'password' => bcrypt('password'),
            'role' => Role::User->value,
            'status' => 'active',
            'announce_key' => bin2hex(random_bytes(16)),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin->value]);
    }

    public function moderator(): static
    {
        return $this->state(fn () => ['role' => Role::Moderator->value]);
    }

    public function banned(): static
    {
        return $this->state(fn () => ['status' => 'banned']);
    }
}
