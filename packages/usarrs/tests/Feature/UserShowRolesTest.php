<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Livewire\Admin\UserShow;
use Marque\Usarrs\Tests\TestUser;

/**
 * The user screen offers only what the actor may do, and refuses the rest
 * when it's called directly.
 */
describe('the role buttons', function () {
    it('offer an admin only the roles below Admin', function () {
        $admin = TestUser::factory()->admin()->create();
        $member = TestUser::factory()->create();

        Livewire::actingAs($admin)
            ->test(UserShow::class, ['user' => $member->id])
            ->assertSee('Set Uploader')
            ->assertSee('Set Moderator')
            ->assertDontSee('Set Admin')
            ->assertDontSee('Set Owner');
    });

    it('offer the owner every role', function () {
        $owner = TestUser::factory()->owner()->create();
        $member = TestUser::factory()->create();

        Livewire::actingAs($owner)
            ->test(UserShow::class, ['user' => $member->id])
            ->assertSee('Set Admin')
            ->assertSee('Set Owner');
    });

    it('offer an admin nothing on another admin', function () {
        $admin = TestUser::factory()->admin()->create();
        $other = TestUser::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(UserShow::class, ['user' => $other->id])
            ->assertDontSee('Set Moderator')
            ->assertDontSee('Ban User');
    });
});

describe('called directly', function () {
    it('refuses an admin granting Owner', function () {
        $admin = TestUser::factory()->admin()->create();
        $member = TestUser::factory()->create();

        Livewire::actingAs($admin)
            ->test(UserShow::class, ['user' => $member->id])
            ->call('setRole', 'owner')
            ->assertForbidden();

        expect($member->fresh()->role)->toBe(Role::User);
    });

    it('refuses an admin banning the owner', function () {
        $admin = TestUser::factory()->admin()->create();
        $owner = TestUser::factory()->owner()->create();

        Livewire::actingAs($admin)
            ->test(UserShow::class, ['user' => $owner->id])
            ->call('ban')
            ->assertForbidden();

        expect($owner->fresh()->status)->toBe('active');
    });

    it('refuses a role that does not exist', function () {
        $owner = TestUser::factory()->owner()->create();
        $member = TestUser::factory()->create();

        Livewire::actingAs($owner)
            ->test(UserShow::class, ['user' => $member->id])
            ->call('setRole', 'emperor')
            ->assertStatus(422);
    });

    it('lets the owner make an admin', function () {
        $owner = TestUser::factory()->owner()->create();
        $member = TestUser::factory()->create();

        Livewire::actingAs($owner)
            ->test(UserShow::class, ['user' => $member->id])
            ->call('setRole', 'admin')
            ->assertOk();

        expect($member->fresh()->role)->toBe(Role::Admin);
    });
});
