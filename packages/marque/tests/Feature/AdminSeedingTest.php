<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Marque\Marque\Install\AdminSeeder;
use Marque\Marque\Tests\TestCase;
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Contracts\UserInterface;

/**
 * The seed itself, against a real users table: who the first account becomes,
 * and what an install that already has staff is told.
 *
 * The one place this package's suite touches a database. Everything else is
 * tested by driving the command, but the seed's rules are queries, and a
 * query is only proven by running it.
 */
class SeedingUser extends Authenticatable implements UserInterface
{
    use HasRoles;

    protected $table = 'users';

    // Stock Laravel's User lists its mass-assignable attributes and leaves
    // `role` out. Mirrored here because that is what the seeder must survive:
    // role passed to create() is dropped without a word.
    protected $fillable = ['name', 'email', 'password'];
}

uses(TestCase::class);

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->string('role')->default('user');
        $table->timestamps();
    });

    $this->seeder = new AdminSeeder;
});

afterEach(function () {
    Schema::dropIfExists('users');
});

function seedingUser(string $email, string $role): SeedingUser
{
    $user = SeedingUser::query()->create(['name' => $email, 'email' => $email, 'password' => 'x']);
    $user->role = $role;
    $user->save();

    return $user;
}

it('creates the first account as the owner', function () {
    expect($this->seeder->seed(SeedingUser::class, 'Ada', 'ada@example.com'))->toBe(AdminSeeder::CREATED);

    $owner = SeedingUser::query()->where('email', 'ada@example.com')->sole();

    expect($owner->isOwner())->toBeTrue()
        ->and($this->seeder->firstAdmin(SeedingUser::class)?->is($owner))->toBeTrue();
});

it('leaves an install with an owner alone, and seeds no second account', function () {
    seedingUser('first@example.com', 'owner');

    expect($this->seeder->seed(SeedingUser::class, 'Ada', 'ada@example.com'))->toBe(AdminSeeder::EXISTS)
        ->and(SeedingUser::query()->count())->toBe(1);
});

it('reports an install whose admins have no owner', function () {
    // An install from before trove 5: an admin, and nobody who can grant Admin.
    seedingUser('old@example.com', 'admin');

    expect($this->seeder->seed(SeedingUser::class, 'Ada', 'ada@example.com'))->toBe(AdminSeeder::EXISTS_WITHOUT_OWNER)
        ->and(SeedingUser::query()->count())->toBe(1)
        ->and($this->seeder->firstAdmin(SeedingUser::class)?->email)->toBe('old@example.com');
});

it('does not take over an address a member already has', function () {
    seedingUser('ada@example.com', 'user');

    expect($this->seeder->seed(SeedingUser::class, 'Ada', 'ada@example.com'))->toBe(AdminSeeder::EMAIL_TAKEN)
        ->and(SeedingUser::query()->sole()->isOwner())->toBeFalse();
});

it('ignores members and moderators when deciding whether staff exist', function () {
    seedingUser('mod@example.com', 'moderator');

    expect($this->seeder->seed(SeedingUser::class, 'Ada', 'ada@example.com'))->toBe(AdminSeeder::CREATED)
        ->and($this->seeder->firstAdmin(SeedingUser::class)?->email)->toBe('ada@example.com');
});
