<?php

declare(strict_types=1);

// #10732 (Job #255). min_ratio is enforced: a member below it, past the grace
// allowance and not exempt, goes on ratio watch with a deadline; still below
// at the deadline, new downloading is paused; back above, it all clears.
// Seeding is never touched here. That is the announce path's half.

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Marque\Bloodhound\Events\MemberRestricted;
use Marque\Bloodhound\Events\RatioWatchStarted;
use Marque\Bloodhound\Events\StandingRestored;
use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Services\RatioEnforcer;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Enums\StandingState;

const GIB = 1024 ** 3;

function member(int $uploaded, int $downloaded, string $role = 'user'): TestUser
{
    static $n = 0;
    $n++;

    $user = TestUser::create(['name' => "M{$n}", 'email' => "m{$n}@example.com", 'password' => 'p', 'role' => $role]);
    $user->forceFill(['uploaded' => $uploaded, 'downloaded' => $downloaded])->save();

    return $user;
}

function enforce(): array
{
    return app(RatioEnforcer::class)->run();
}

function standingOf(TestUser $user): StandingState
{
    return app(RatioEnforcer::class)->standingFor($user)->state;
}

beforeEach(function () {
    config([
        'bloodhound.ratio_mode' => 'full',
        'bloodhound.min_ratio' => 0.5,
        'bloodhound.enforcement.watch_days' => 14,
        'bloodhound.enforcement.grace_gb' => 5,
        'bloodhound.enforcement.grace_bytes' => null,
        'bloodhound.enforcement.exempt_role' => 'moderator',
    ]);
    Event::fake([RatioWatchStarted::class, MemberRestricted::class, StandingRestored::class]);
});

describe('the watch', function () {
    it('puts a member below the ratio, past the grace allowance, on watch with a deadline', function () {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 2 * GIB, downloaded: 10 * GIB);

        enforce();

        $standing = app(RatioEnforcer::class)->standingFor($member);
        expect($standing->state)->toBe(StandingState::Watch)
            ->and($standing->until?->toDateTimeString())->toBe('2026-11-15 10:00:00')
            ->and($standing->reason)->toContain('0.20')->toContain('0.50');
        Event::assertDispatchedTimes(RatioWatchStarted::class, 1);
    });

    it('starts the watch once, however often it runs', function () {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 2 * GIB, downloaded: 10 * GIB);

        enforce();
        $this->travelTo('2026-11-02 10:00:00');
        enforce();

        expect(app(RatioEnforcer::class)->standingFor($member)->until?->toDateTimeString())->toBe('2026-11-15 10:00:00');
        Event::assertDispatchedTimes(RatioWatchStarted::class, 1);
    });

    it('leaves a member alone at or above the ratio', function () {
        $member = member(uploaded: 5 * GIB, downloaded: 10 * GIB);

        enforce();

        expect(standingOf($member))->toBe(StandingState::Ok)
            ->and(MemberStanding::query()->count())->toBe(0);
    });
});

describe('the restriction', function () {
    it('pauses downloading for a member still below at the deadline', function () {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 2 * GIB, downloaded: 10 * GIB);
        enforce();

        $this->travelTo('2026-11-15 10:00:00');
        enforce();

        $standing = app(RatioEnforcer::class)->standingFor($member);
        expect($standing->state)->toBe(StandingState::Restricted)
            ->and($standing->reason)->toContain('0.50');
        Event::assertDispatchedTimes(MemberRestricted::class, 1);
    });

    it('waits for the deadline', function () {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 2 * GIB, downloaded: 10 * GIB);
        enforce();

        $this->travelTo('2026-11-15 09:59:59');
        enforce();

        expect(standingOf($member))->toBe(StandingState::Watch);
        Event::assertNotDispatched(MemberRestricted::class);
    });
});

describe('recovering', function () {
    it('clears a watch once the member is back above', function () {
        $member = member(uploaded: 2 * GIB, downloaded: 10 * GIB);
        enforce();

        $member->forceFill(['uploaded' => 6 * GIB])->save();
        enforce();

        expect(standingOf($member))->toBe(StandingState::Ok)
            ->and(MemberStanding::query()->count())->toBe(0);
        Event::assertDispatched(StandingRestored::class, fn ($e) => $e->previous === StandingState::Watch);
    });

    it('lifts a restriction once the member is back above', function () {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 2 * GIB, downloaded: 10 * GIB);
        enforce();
        $this->travelTo('2026-11-16 10:00:00');
        enforce();

        $member->forceFill(['uploaded' => 5 * GIB])->save();
        enforce();

        expect(standingOf($member))->toBe(StandingState::Ok);
        Event::assertDispatched(StandingRestored::class, fn ($e) => $e->previous === StandingState::Restricted
            && $e->userId === $member->id);
    });

    it('starts a fresh watch if the member falls below again', function () {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 2 * GIB, downloaded: 10 * GIB);
        enforce();
        $member->forceFill(['uploaded' => 6 * GIB])->save();
        enforce();

        $this->travelTo('2026-12-01 10:00:00');
        $member->forceFill(['downloaded' => 20 * GIB])->save();
        enforce();

        expect(app(RatioEnforcer::class)->standingFor($member)->until?->toDateTimeString())->toBe('2026-12-15 10:00:00');
    });
});

describe('who is never watched', function () {
    it('leaves a member alone until they have downloaded the grace allowance', function () {
        $member = member(uploaded: 0, downloaded: 4 * GIB);

        enforce();

        expect(standingOf($member))->toBe(StandingState::Ok);
    });

    // The allowance is set in GB as the tracker shows sizes, 1024-based, so
    // 20 here is what a member sees as 20 GB downloaded.
    it('reads the grace in GB, 1024-based, decimals included', function () {
        config(['bloodhound.enforcement.grace_gb' => 2.5]);
        $under = member(uploaded: 0, downloaded: (int) (2.5 * GIB) - 1);
        $at = member(uploaded: 0, downloaded: (int) (2.5 * GIB));

        enforce();

        expect(standingOf($under))->toBe(StandingState::Ok)
            ->and(standingOf($at))->toBe(StandingState::Watch);
    });

    it('falls back to 5 GB when grace_gb is unset', function () {
        config(['bloodhound.enforcement.grace_gb' => null]);
        $under = member(uploaded: 0, downloaded: 5 * GIB - 1);
        $at = member(uploaded: 0, downloaded: 5 * GIB);

        enforce();

        expect(standingOf($under))->toBe(StandingState::Ok)
            ->and(standingOf($at))->toBe(StandingState::Watch);
    });

    it('falls back to 5 GB when grace_gb is not a number', function () {
        config(['bloodhound.enforcement.grace_gb' => '20GB']);
        $member = member(uploaded: 0, downloaded: 6 * GIB);

        enforce();

        expect(standingOf($member))->toBe(StandingState::Watch);
    });

    it('enforces from the first byte with a grace of 0', function () {
        config(['bloodhound.enforcement.grace_gb' => 0]);
        $member = member(uploaded: 0, downloaded: 1);

        enforce();

        expect(standingOf($member))->toBe(StandingState::Watch);
    });

    // Before 9.2 it was set in bytes. A tracker that set it keeps its number.
    it('still honours a grace_bytes that is set, over grace_gb', function () {
        config(['bloodhound.enforcement.grace_gb' => 5, 'bloodhound.enforcement.grace_bytes' => 20 * GIB]);
        $member = member(uploaded: 0, downloaded: 10 * GIB);

        enforce();

        expect(standingOf($member))->toBe(StandingState::Ok);
    });

    // Every config published on 9.0 or 9.1 carries grace_bytes at its 5 GiB
    // default. That isn't a choice, so it must not shadow grace_gb.
    it('ignores a grace_bytes at the 9.0/9.1 default, so grace_gb applies', function () {
        config(['bloodhound.enforcement.grace_gb' => 20, 'bloodhound.enforcement.grace_bytes' => 5 * GIB]);
        $member = member(uploaded: 0, downloaded: 10 * GIB);

        enforce();

        expect(standingOf($member))->toBe(StandingState::Ok);
    });

    // 9.1 read an empty BLOODHOUND_RATIO_GRACE_BYTES= as a grace of 0. It must
    // still mean 0, not fall through to grace_gb's 5 GB.
    it('reads an empty or non-numeric grace_bytes as 9.1 did: 0', function () {
        config(['bloodhound.enforcement.grace_bytes' => '']);
        $member = member(uploaded: 0, downloaded: 1);

        enforce();

        expect(standingOf($member))->toBe(StandingState::Watch);
    });

    it('treats staff at or above the exempt role as exempt', function () {
        $moderator = member(uploaded: 0, downloaded: 50 * GIB, role: 'moderator');
        $admin = member(uploaded: 0, downloaded: 50 * GIB, role: 'admin');

        enforce();

        expect(standingOf($moderator))->toBe(StandingState::Exempt)
            ->and(standingOf($admin))->toBe(StandingState::Exempt)
            ->and(MemberStanding::query()->whereNotNull('state')->where('state', '!=', 'ok')->count())->toBe(0);
    });

    it('lifts a restriction on a member promoted to the exempt role', function () {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 0, downloaded: 50 * GIB);
        enforce();
        $this->travelTo('2026-11-16 10:00:00');
        enforce();

        $member->forceFill(['role' => 'moderator'])->save();
        enforce();

        expect(standingOf($member))->toBe(StandingState::Exempt);
        Event::assertDispatched(StandingRestored::class, fn ($e) => $e->previous === StandingState::Restricted);
    });

    it('records who exempted a member and why, and never watches them', function () {
        $admin = member(uploaded: 0, downloaded: 0, role: 'admin');
        $member = member(uploaded: 0, downloaded: 50 * GIB);

        app(RatioEnforcer::class)->exempt($member->id, by: $admin->id, reason: 'Donor');
        enforce();

        $row = MemberStanding::query()->where('user_id', $member->id)->sole();
        expect(standingOf($member))->toBe(StandingState::Exempt)
            ->and($row->exempt_reason)->toBe('Donor')
            ->and($row->exempted_by)->toBe($admin->id)
            ->and($row->exempted_at)->not->toBeNull();
    });

    it('lifts a restriction the moment an admin exempts the member', function () {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 0, downloaded: 50 * GIB);
        enforce();
        $this->travelTo('2026-11-16 10:00:00');
        enforce();

        app(RatioEnforcer::class)->exempt($member->id, by: 1, reason: 'Appeal upheld');

        expect(standingOf($member))->toBe(StandingState::Exempt);
        Event::assertDispatched(StandingRestored::class, fn ($e) => $e->previous === StandingState::Restricted);
    });

    it('judges a member again once the exemption is removed', function () {
        $member = member(uploaded: 0, downloaded: 50 * GIB);
        app(RatioEnforcer::class)->exempt($member->id, by: 1, reason: 'Donor');
        enforce();

        app(RatioEnforcer::class)->unexempt($member->id);
        enforce();

        expect(standingOf($member))->toBe(StandingState::Watch);
    });
});

describe('modes', function () {
    it('enforces nothing with ratio_mode off or seedtime, and clears what was there', function (string $mode) {
        $this->travelTo('2026-11-01 10:00:00');
        $member = member(uploaded: 0, downloaded: 50 * GIB);
        enforce();
        $this->travelTo('2026-11-16 10:00:00');
        enforce();

        config(['bloodhound.ratio_mode' => $mode]);
        enforce();

        expect(standingOf($member))->toBe(StandingState::Ok);
        Event::assertDispatched(StandingRestored::class, fn ($e) => $e->previous === StandingState::Restricted);
    })->with(['off', 'seedtime']);

    it('keeps an admin\'s exemption through a mode change', function () {
        $member = member(uploaded: 0, downloaded: 50 * GIB);
        app(RatioEnforcer::class)->exempt($member->id, by: 1, reason: 'Donor');

        config(['bloodhound.ratio_mode' => 'off']);
        enforce();
        config(['bloodhound.ratio_mode' => 'full']);
        enforce();

        expect(standingOf($member))->toBe(StandingState::Exempt);
    });
});

describe('the command', function () {
    it('runs hourly on the scheduler', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'bloodhound:enforce-ratio'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *');
    });

    it('reports what it changed', function () {
        member(uploaded: 0, downloaded: 50 * GIB);

        $this->artisan('bloodhound:enforce-ratio')
            ->expectsOutputToContain('1 put on watch')
            ->assertSuccessful();
    });

    it('exempts, shows and unexempts through bloodhound:standing', function () {
        $admin = member(uploaded: 0, downloaded: 0, role: 'admin');
        $member = member(uploaded: 0, downloaded: 50 * GIB);

        $this->artisan('bloodhound:standing', ['action' => 'exempt', 'user' => $member->id, '--reason' => 'Donor', '--by' => $admin->id])
            ->assertSuccessful();
        $this->artisan('bloodhound:standing', ['action' => 'show', 'user' => $member->id])
            ->expectsOutputToContain('exempt')
            ->expectsOutputToContain('Donor')
            ->assertSuccessful();
        $this->artisan('bloodhound:standing', ['action' => 'unexempt', 'user' => $member->id, '--by' => $admin->id])
            ->assertSuccessful();

        expect(MemberStanding::query()->where('user_id', $member->id)->value('exempted_at'))->toBeNull();
    });

    // TouchUp #57: who granted an exemption belongs on record. 9.x warned that
    // --by would be required from 10.0; it is.
    it('refuses to exempt or unexempt without --by', function () {
        $member = member(uploaded: 0, downloaded: 50 * GIB);

        $this->artisan('bloodhound:standing', ['action' => 'exempt', 'user' => $member->id, '--reason' => 'Donor'])
            ->expectsOutputToContain('--by')
            ->assertFailed();
        $this->artisan('bloodhound:standing', ['action' => 'unexempt', 'user' => $member->id])
            ->expectsOutputToContain('--by')
            ->assertFailed();

        expect(MemberStanding::query()->where('user_id', $member->id)->value('exempted_at'))->toBeNull();
    });

    it('refuses a --by that names no user, and records nothing', function () {
        $member = member(uploaded: 0, downloaded: 50 * GIB);

        foreach (['999999', 'admin'] as $by) {
            $this->artisan('bloodhound:standing', ['action' => 'exempt', 'user' => $member->id, '--reason' => 'Donor', '--by' => $by])
                ->assertFailed();
        }

        expect(MemberStanding::query()->where('user_id', $member->id)->whereNotNull('exempted_at')->exists())->toBeFalse();
    });

    it('records the admin a valid --by names', function () {
        $admin = member(uploaded: 0, downloaded: 0, role: 'admin');
        $member = member(uploaded: 0, downloaded: 50 * GIB);

        $this->artisan('bloodhound:standing', ['action' => 'exempt', 'user' => $member->id, '--reason' => 'Donor', '--by' => $admin->id])
            ->assertSuccessful();

        expect(MemberStanding::query()->where('user_id', $member->id)->value('exempted_by'))->toBe($admin->id);
    });

    it('refuses an exemption without a reason', function () {
        $member = member(uploaded: 0, downloaded: 50 * GIB);

        $this->artisan('bloodhound:standing', ['action' => 'exempt', 'user' => $member->id])
            ->assertFailed();
    });
});
