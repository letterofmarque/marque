<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\StaffAction;
use Marque\Trove\Tests\TestUser;

describe('StaffAction', function () {
    it('holds what a staff member did, to what, and why', function () {
        $at = CarbonImmutable::parse('2026-10-26 12:00:00');

        $action = new StaffAction(
            actorId: 7,
            action: 'usarrs.user.role_changed',
            subjectType: 'user',
            subjectId: 42,
            reason: 'Promoted after a month of good reports',
            changes: ['role' => ['from' => 'uploader', 'to' => 'moderator']],
            at: $at,
        );

        expect($action->actorId)->toBe(7)
            ->and($action->action)->toBe('usarrs.user.role_changed')
            ->and($action->package())->toBe('usarrs')
            ->and($action->subjectType)->toBe('user')
            ->and($action->subjectId)->toBe(42)
            ->and($action->reason)->toBe('Promoted after a month of good reports')
            ->and($action->changes)->toBe(['role' => ['from' => 'uploader', 'to' => 'moderator']])
            ->and($action->at)->toBe($at);
    });

    it('is stamped now when no time is given', function () {
        CarbonImmutable::setTestNow('2026-10-26 09:30:00');

        $action = new StaffAction(actorId: 1, action: 'trove.torrent.deleted', subjectType: 'torrent', subjectId: 1);

        expect($action->at->toDateTimeString())->toBe('2026-10-26 09:30:00');

        CarbonImmutable::setTestNow();
    });

    it('builds from the actor and the subject model', function () {
        $actor = TestUser::factory()->moderator()->create();
        $torrent = Torrent::factory()->create();

        $action = StaffAction::of($actor, 'trove.torrent.edited', $torrent, reason: 'Wrong season');

        expect($action->actorId)->toBe($actor->getAuthIdentifier())
            ->and($action->subjectType)->toBe($torrent->getMorphClass())
            ->and($action->subjectId)->toBe($torrent->getKey())
            ->and($action->reason)->toBe('Wrong season');
    });

    it('treats a blank reason as none', function () {
        $action = new StaffAction(actorId: 1, action: 'trove.torrent.deleted', subjectType: 'torrent', subjectId: 1, reason: '   ');

        expect($action->reason)->toBeNull();
    });

    it('refuses an action that is not namespaced by its package', function (string $name) {
        new StaffAction(actorId: 1, action: $name, subjectType: 'user', subjectId: 1);
    })->with(['banned', 'usarrs', 'Usarrs.User.Banned', 'usarrs..banned', '.usarrs.banned', 'usarrs.user.banned.', 'usarrs user banned', ''])
        ->throws(InvalidArgumentException::class);

    it('refuses a change that is not a from and a to', function (array $changes) {
        new StaffAction(actorId: 1, action: 'usarrs.user.role_changed', subjectType: 'user', subjectId: 1, changes: $changes);
    })->with([
        'missing to' => [['role' => ['from' => 'user']]],
        'extra key' => [['role' => ['from' => 'user', 'to' => 'admin', 'by' => 'x']]],
        'not an array' => [['role' => 'admin']],
        'unnamed field' => [[['from' => 'user', 'to' => 'admin']]],
        'a nested value' => [['role' => ['from' => ['a'], 'to' => 'admin']]],
    ])->throws(InvalidArgumentException::class);

    it('accepts scalar and null values in a change', function () {
        $action = new StaffAction(
            actorId: 1,
            action: 'bloodhound.exemption.granted',
            subjectType: 'user',
            subjectId: 1,
            changes: ['exempt' => ['from' => false, 'to' => true], 'until' => ['from' => null, 'to' => '2026-12-01'], 'grace_gb' => ['from' => 5, 'to' => 20.5]],
        );

        expect($action->changes)->toHaveCount(3);
    });
});
