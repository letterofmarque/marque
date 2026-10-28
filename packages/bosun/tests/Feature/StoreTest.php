<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marque\Bosun\Models\StaffActionEntry;
use Marque\Bosun\Store\DatabaseStaffActions;
use Marque\Bosun\Tests\TestUser;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Enums\Role;
use Marque\Trove\Support\StaffAction;
use Marque\Usarrs\Services\UserAdminService;

describe('the binding', function () {
    it('replaces trove\'s do-nothing default', function () {
        expect(app(StaffActionsInterface::class))->toBeInstanceOf(DatabaseStaffActions::class);
    });
});

describe('what is stored', function () {
    it('keeps every field a package records', function () {
        app(StaffActionsInterface::class)->record(new StaffAction(
            actorId: 7,
            action: 'usarrs.user.role_changed',
            subjectType: 'user',
            subjectId: 42,
            reason: 'Good reports',
            changes: ['role' => ['from' => 'user', 'to' => 'moderator']],
            at: now()->subHour(),
        ));

        $entry = StaffActionEntry::query()->sole();

        expect($entry->actor_id)->toBe(7)
            ->and($entry->action)->toBe('usarrs.user.role_changed')
            ->and($entry->package)->toBe('usarrs')
            ->and($entry->subject_type)->toBe('user')
            ->and($entry->subject_id)->toBe('42')
            ->and($entry->reason)->toBe('Good reports')
            // toEqual: MySQL's JSON type reorders keys, and from/to are read by name.
            ->and($entry->changes)->toEqual(['role' => ['from' => 'user', 'to' => 'moderator']])
            ->and($entry->created_at->toDateTimeString())->toBe(now()->subHour()->toDateTimeString());
    });

    it('stores a usarrs ban, end to end', function () {
        $owner = TestUser::factory()->owner()->create();
        $member = TestUser::factory()->create();

        app(UserAdminService::class)->ban($member, actor: $owner, reason: 'Ratio cheating');

        $entry = StaffActionEntry::query()->sole();

        expect($entry->action)->toBe('usarrs.user.banned')
            ->and($entry->actor_id)->toBe($owner->id)
            ->and($entry->subject_id)->toBe((string) $member->id)
            ->and($entry->reason)->toBe('Ratio cheating')
            ->and($entry->changes)->toEqual(['status' => ['from' => 'active', 'to' => 'banned']])
            ->and($entry->actor->is($owner))->toBeTrue();
    });
});

describe('failing open', function () {
    // The point of the store's savepoint: a record that can't be stored never
    // takes the staff action down with it.
    it('lets a real staff action go ahead, and logs the gap, when the record fails', function () {
        StaffActionEntry::creating(fn () => throw new RuntimeException('insert refused'));
        Log::spy();

        $owner = TestUser::factory()->owner()->create();
        $member = TestUser::factory()->create();

        app(UserAdminService::class)->ban($member, actor: $owner, reason: 'Ratio cheating');

        expect($member->fresh()->status)->toBe('banned')
            ->and(StaffActionEntry::query()->count())->toBe(0);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not be recorded'));
    });

    it('keeps the caller\'s transaction usable after a rejected record', function () {
        if (DB::connection()->getDriverName() === 'sqlite') {
            StaffActionEntry::creating(fn () => throw new RuntimeException('insert refused'));
        }

        $owner = TestUser::factory()->owner()->create();
        $member = TestUser::factory()->create();

        DB::transaction(function () use ($member): void {
            // Rejected inside the caller's transaction...
            app(StaffActionsInterface::class)->record(new StaffAction(
                actorId: 'not-a-number',
                action: 'usarrs.user.banned',
                subjectType: 'user',
                subjectId: $member->id,
            ));

            // ...and the caller's next statement still runs, and commits.
            $member->update(['status' => 'banned']);
        });

        expect($member->fresh()->status)->toBe('banned')
            ->and(StaffActionEntry::query()->count())->toBe(0);
    });

    it('lets a usarrs role change commit when the database rejects its record, on every engine', function () {
        Log::spy();

        $owner = TestUser::factory()->owner()->create();

        // Its morph class is longer than staff_actions.subject_type, so the
        // database itself refuses the record's INSERT: PostgreSQL, MySQL and
        // MariaDB by the column length, SQLite by a test trigger enforcing the
        // same. Inside setRole's transaction, which is inside the test's: on
        // PostgreSQL only bosun's savepoint keeps either of them usable.
        $member = LongMorphUser::query()->create([
            'name' => 'Long', 'email' => 'long@example.com', 'password' => 'x', 'role' => 'user',
        ]);

        app(UserAdminService::class)->setRole($member, Role::Moderator, actor: $owner, reason: 'Promoted');

        expect($member->fresh()->role)->toBe(Role::Moderator)
            ->and(StaffActionEntry::query()->count())->toBe(0);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not be recorded'));
    });
});

class LongMorphUser extends TestUser
{
    public function getMorphClass(): string
    {
        return str_repeat('x', 300);
    }
}

it('refuses a non-integer actor id the same way on every engine', function () {
    Log::spy();

    app(StaffActionsInterface::class)->record(new StaffAction(
        actorId: '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
        action: 'usarrs.user.banned',
        subjectType: 'user',
        subjectId: 2,
    ));

    expect(StaffActionEntry::query()->count())->toBe(0);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'integer user ids only'));
});
