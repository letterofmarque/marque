<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Bosun\Livewire\Staff;
use Marque\Bosun\Models\StaffActionEntry;
use Marque\Bosun\Models\StaffPresence;
use Marque\Bosun\Tests\TestUser;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreenRegistry;

function staffActed(TestUser $actor, int $daysAgo): void
{
    StaffActionEntry::query()->create([
        'actor_id' => $actor->id,
        'action' => 'parley.thread.locked',
        'package' => 'parley',
        'subject_type' => 'thread',
        'subject_id' => '1',
        'created_at' => now()->subDays($daysAgo),
    ]);
}

beforeEach(function () {
    $this->owner = TestUser::factory()->owner()->create(['name' => 'Olive Owner']);
    $this->admin = TestUser::factory()->admin()->create(['name' => 'Adam Admin']);
    $this->moderator = TestUser::factory()->moderator()->create(['name' => 'Mona Mod']);
    $this->member = TestUser::factory()->create(['name' => 'Mel Member', 'email' => 'mel@example.com']);
});

it('is registered as an admin screen for admins and above', function () {
    $screen = app(AdminScreenRegistry::class)->find('bosun-staff');

    expect($screen?->component)->toBe('bosun-staff')
        ->and($screen->minimumRole)->toBe(Role::Admin);
});

describe('the roster', function () {
    it('lists the staff by role, and nobody else', function () {
        Livewire::actingAs($this->owner)
            ->test(Staff::class)
            ->assertSeeInOrder(['Olive Owner', 'Adam Admin', 'Mona Mod'])
            ->assertDontSee('Mel Member');
    });

    it('shows how much each did over 7, 30 and 90 days, and when they were last seen', function () {
        foreach ([1, 2, 20, 60, 200] as $daysAgo) {
            staffActed($this->moderator, $daysAgo);
        }
        StaffPresence::query()->create(['user_id' => $this->moderator->id, 'last_seen_at' => now()->subHours(3)]);

        $screen = Livewire::actingAs($this->owner)->test(Staff::class);

        expect($screen->viewData('counts')[$this->moderator->id])->toBe([7 => 2, 30 => 3, 90 => 4])
            ->and($screen->viewData('lastSeen')[$this->moderator->id]->diffInHours(now()))->toEqualWithDelta(3, 0.1);

        $screen->assertSee('3 hours ago')->assertSee('Never');
    });
});

describe('changing a role', function () {
    it('lets the owner make an admin, with the reason recorded', function () {
        Livewire::actingAs($this->owner)
            ->test(Staff::class)
            ->call('startChange', $this->moderator->id)
            ->set('role', 'admin')
            ->set('reason', 'Ran the forum well for a year')
            ->call('applyChange')
            ->assertHasNoErrors();

        $entry = StaffActionEntry::query()->where('action', 'usarrs.user.role_changed')->sole();

        expect($this->moderator->fresh()->role)->toBe(Role::Admin)
            ->and($entry->actor_id)->toBe($this->owner->id)
            ->and($entry->reason)->toBe('Ran the forum well for a year');
    });

    it('requires a reason', function () {
        Livewire::actingAs($this->owner)
            ->test(Staff::class)
            ->call('startChange', $this->moderator->id)
            ->set('role', 'user')
            ->set('reason', '  ')
            ->call('applyChange')
            ->assertHasErrors('reason');

        expect($this->moderator->fresh()->role)->toBe(Role::Moderator);
    });

    it('offers an admin only the roles below Admin, and refuses the rest', function () {
        $screen = Livewire::actingAs($this->admin)->test(Staff::class)->call('startChange', $this->moderator->id);

        expect(array_map(fn (Role $r) => $r->value, $screen->viewData('offered')))->toBe(['uploader', 'user']);

        $screen->set('role', 'admin')->set('reason', 'Trying it')->call('applyChange')->assertForbidden();

        expect($this->moderator->fresh()->role)->toBe(Role::Moderator);
    });

    it('offers an admin nothing for another admin or the owner', function () {
        $screen = Livewire::actingAs($this->admin)->test(Staff::class);

        expect($screen->viewData('changeable'))->toEqual([$this->owner->id => false, $this->admin->id => false, $this->moderator->id => true]);
    });

    it('adds a member to the staff by address', function () {
        Livewire::actingAs($this->admin)
            ->test(Staff::class)
            ->set('addEmail', 'mel@example.com')
            ->set('role', 'moderator')
            ->set('reason', 'Helpful in the forums')
            ->call('addToStaff')
            ->assertHasNoErrors();

        expect($this->member->fresh()->role)->toBe(Role::Moderator)
            ->and(StaffActionEntry::query()->where('subject_id', (string) $this->member->id)->value('reason'))->toBe('Helpful in the forums');
    });

    it('says so when nobody has the address', function () {
        Livewire::actingAs($this->admin)
            ->test(Staff::class)
            ->set('addEmail', 'nobody@example.com')
            ->set('role', 'moderator')
            ->set('reason', 'x')
            ->call('addToStaff')
            ->assertHasErrors('addEmail');
    });
});

it('refuses a moderator', function () {
    Livewire::actingAs($this->moderator)->test(Staff::class)->assertForbidden();
});
