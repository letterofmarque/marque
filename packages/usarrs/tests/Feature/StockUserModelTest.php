<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Usarrs\Services\UserAdminService;
use Marque\Usarrs\Tests\TestUser;

/**
 * Laravel's stock User lists its mass-assignable attributes (name, email,
 * password), and role and status aren't among them. The suite's TestUser is
 * unguarded, so a change written with update() passed here and did nothing in
 * a real app: the clean room found a role change, and therefore bans, that
 * never saved (Job #306).
 */
class StockUser extends TestUser
{
    protected $guarded = ['*'];

    protected $fillable = ['name', 'email', 'password'];
}

beforeEach(function () {
    $this->owner = TestUser::factory()->owner()->create();
    $this->member = StockUser::query()->find(TestUser::factory()->create()->id);
});

it('changes the role of a stock User', function () {
    app(UserAdminService::class)->setRole($this->member, Role::Moderator, actor: $this->owner);

    expect($this->member->fresh()->role)->toBe(Role::Moderator);
});

it('bans and unbans a stock User', function () {
    app(UserAdminService::class)->ban($this->member, actor: $this->owner);
    expect($this->member->fresh()->status)->toBe('banned');

    app(UserAdminService::class)->unban($this->member, actor: $this->owner);
    expect($this->member->fresh()->status)->toBe('active');
});

it('makes a stock User an owner from the console', function () {
    config(['trove.user_model' => StockUser::class]);

    $this->artisan('usarrs:owner', ['email' => $this->member->email])->assertSuccessful();

    expect($this->member->fresh()->role)->toBe(Role::Owner);
});
