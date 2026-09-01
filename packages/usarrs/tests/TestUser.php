<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Illuminate\Contracts\Auth\MustVerifyEmail;
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

// implements MustVerifyEmail (the CONTRACT) is required in addition to the
// trait Illuminate\Foundation\Auth\User already `use`s — EnsureEmailIsVerified
// middleware checks `instanceof \Illuminate\Contracts\Auth\MustVerifyEmail`,
// not "has the trait's methods". Missing this silently makes 'verified'
// middleware a permanent no-op rather than erroring — the exact trap job
// #10602 Gap 7 exists to fix elsewhere; documented explicitly in usarrs'
// README as a required step for consuming apps, not just implied here.
class TestUser extends Authenticatable implements UserInterface, PasskeyUser, MustVerifyEmail
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
            'email_verified_at' => now(),
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

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
