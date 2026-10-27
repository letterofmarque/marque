<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Bosun\Livewire\AuditLog;
use Marque\Bosun\Models\StaffActionEntry;
use Marque\Bosun\Tests\TestUser;
use Marque\Trove\Registry\AdminScreenRegistry;

function logged(TestUser $actor, string $action, string $subjectId, int $daysAgo = 0, ?string $reason = null): StaffActionEntry
{
    return StaffActionEntry::query()->create([
        'actor_id' => $actor->id,
        'action' => $action,
        'package' => strstr($action, '.', true),
        'subject_type' => 'user',
        'subject_id' => $subjectId,
        'reason' => $reason,
        'changes' => ['status' => ['from' => 'active', 'to' => 'banned']],
        'created_at' => now()->subDays($daysAgo),
    ]);
}

beforeEach(function () {
    $this->owner = TestUser::factory()->owner()->create(['name' => 'Olive Owner']);
    $this->admin = TestUser::factory()->admin()->create(['name' => 'Adam Admin']);
    $this->moderator = TestUser::factory()->moderator()->create(['name' => 'Mona Mod']);
});

it('is registered as an admin screen for admins and above', function () {
    $screen = app(AdminScreenRegistry::class)->find('bosun-audit-log');

    expect($screen)->not->toBeNull()
        ->and($screen->component)->toBe('bosun-audit-log')
        ->and($screen->minimumRole->value)->toBe('admin');
});

it('shows each action with who, what, whom, why and what changed', function () {
    logged($this->admin, 'usarrs.user.banned', '42', reason: 'Ratio cheating');

    Livewire::actingAs($this->owner)
        ->test(AuditLog::class)
        ->assertSee('Adam Admin')
        ->assertSee('usarrs.user.banned')
        ->assertSee('user #42')
        ->assertSee('Ratio cheating')
        ->assertSee('active')
        ->assertSee('banned');
});

it('filters by staff member, action, subject and date', function () {
    logged($this->admin, 'usarrs.user.banned', '1', daysAgo: 1, reason: 'first');
    logged($this->moderator, 'parley.thread.locked', '2', daysAgo: 1, reason: 'second');
    logged($this->admin, 'usarrs.user.unbanned', '3', daysAgo: 40, reason: 'third');

    $screen = Livewire::actingAs($this->admin)->test(AuditLog::class);

    $screen->set('actor', (string) $this->moderator->id)
        ->assertSee('second')->assertDontSee('first')->assertDontSee('third');

    $screen->set('actor', '')->set('action', 'usarrs.user.unbanned')
        ->assertSee('third')->assertDontSee('first')->assertDontSee('second');

    $screen->set('action', '')->set('subjectType', 'user')->set('subjectId', '1')
        ->assertSee('first')->assertDontSee('second')->assertDontSee('third');

    $screen->set('subjectType', '')->set('subjectId', '')->set('from', now()->subDays(7)->toDateString())
        ->assertSee('first')->assertSee('second')->assertDontSee('third');

    $screen->set('from', '')->set('to', now()->subDays(30)->toDateString())
        ->assertSee('third')->assertDontSee('first');
});

it('shows the newest first, a page at a time', function () {
    foreach (range(1, 30) as $i) {
        logged($this->admin, 'usarrs.user.banned', (string) $i, reason: "reason-{$i}-end");
    }

    Livewire::actingAs($this->owner)
        ->test(AuditLog::class)
        ->assertSee('reason-30-end')
        ->assertDontSee('reason-1-end');
});

it('refuses a moderator', function () {
    Livewire::actingAs($this->moderator)->test(AuditLog::class)->assertForbidden();
});
