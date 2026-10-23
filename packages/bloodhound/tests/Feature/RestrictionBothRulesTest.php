<?php

declare(strict_types=1);

// Job #256 step 4: one restricted standing for both rules. max_strikes active
// hit-and-run strikes restrict a member as a lapsed ratio watch does, through
// the same refusal and download gate. A member restricted by both stays
// restricted until both clear, and the reason says which applies.

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Events\MemberRestricted;
use Marque\Bloodhound\Events\StandingRestored;
use Marque\Bloodhound\Models\HitAndRunStrike;
use Marque\Bloodhound\Services\HitAndRunEnforcer;
use Marque\Bloodhound\Services\RatioEnforcer;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Enums\StandingState;
use Marque\Trove\Models\Torrent;

const BOTH_GIB = 1024 ** 3;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config([
        'bloodhound.ratio_mode' => 'full',
        'bloodhound.min_ratio' => 0.5,
        'bloodhound.enforcement.watch_days' => 14,
        'bloodhound.enforcement.grace_bytes' => 5 * BOTH_GIB,
        'bloodhound.hit_and_run.enabled' => true,
        'bloodhound.hit_and_run.max_strikes' => 3,
        'bloodhound.anti_cheat.enabled' => false,
    ]);
    Event::fake([MemberRestricted::class, StandingRestored::class]);

    // The rule starts counting the first time it runs, and only strikes since
    // then count. Started a day back, as on a tracker already running it: a
    // strike planted at now() before the first run otherwise fell a second
    // short when the clock ticked over in between (nightly, MySQL, 2026-10-22).
    $this->travelTo(now()->subDay());
    app(HitAndRunEnforcer::class)->since();
    $this->travelBack();

    $this->member = makeTrackerUser('bothbothbothbothbothbothbothboth', 'both@example.com');
    $this->member->forceFill(['uploaded' => 10 * BOTH_GIB, 'downloaded' => 10 * BOTH_GIB])->save();
});

function strikes(TestUser $member, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        HitAndRunStrike::query()->create([
            'user_id' => $member->id,
            'torrent_id' => 9000 + $i + HitAndRunStrike::query()->count(),
            'prewarned_at' => now()->subDays(3),
            'struck_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);
    }
}

function judgeBoth(): void
{
    app(RatioEnforcer::class)->run();
}

function standingNow(TestUser $member)
{
    return app(RatioEnforcer::class)->standingFor($member);
}

it('restricts a member with max_strikes active strikes, whose ratio is fine, and says why', function () {
    strikes($this->member, 3);

    judgeBoth();

    $standing = standingNow($this->member);
    expect($standing->state)->toBe(StandingState::Restricted)
        ->and($standing->reason)->toContain('3 hit-and-run strikes')
        ->and($standing->reason)->not->toContain('ratio is');
    Event::assertDispatchedTimes(MemberRestricted::class, 1);
});

it('leaves a member below max_strikes alone', function () {
    strikes($this->member, 2);

    judgeBoth();

    expect(standingNow($this->member)->state)->toBe(StandingState::Ok);
});

it('restores the member once strikes fall below max_strikes', function () {
    strikes($this->member, 3);
    judgeBoth();

    HitAndRunStrike::query()->where('user_id', $this->member->id)->limit(1)->update(['cleared_at' => now()]);
    judgeBoth();

    expect(standingNow($this->member)->state)->toBe(StandingState::Ok);
    Event::assertDispatched(StandingRestored::class, fn ($e) => $e->previous === StandingState::Restricted);
});

it('refuses a hit-and-run-restricted member\'s new leech, at announce and download, as for the ratio', function () {
    strikes($this->member, 3);
    judgeBoth();
    $torrent = Torrent::create(['name' => 'New', 'info_hash' => str_repeat('c', 40), 'size' => 1_000, 'user_id' => $this->member->id]);

    expect(app(RatioEnforcer::class)->leechRefusal($this->member->id, $torrent->id))->toContain('hit-and-run');
});

it('names both rules when both apply, and stays restricted until both clear', function () {
    $this->travelTo('2026-11-01 00:00:00');
    $this->member->forceFill(['uploaded' => BOTH_GIB, 'downloaded' => 10 * BOTH_GIB])->save();
    judgeBoth();
    $this->travelTo('2026-11-16 00:00:00');
    strikes($this->member, 3);
    judgeBoth();

    $reason = standingNow($this->member)->reason;
    expect($reason)->toContain('ratio is 0.10')->toContain('3 hit-and-run strikes');

    HitAndRunStrike::query()->where('user_id', $this->member->id)->update(['cleared_at' => now()]);
    judgeBoth();
    expect(standingNow($this->member)->state)->toBe(StandingState::Restricted)
        ->and(standingNow($this->member)->reason)->not->toContain('hit-and-run');
});

it('goes back to the ratio watch, not ok, when strikes clear during a watch', function () {
    $this->travelTo('2026-11-01 00:00:00');
    $this->member->forceFill(['uploaded' => BOTH_GIB, 'downloaded' => 10 * BOTH_GIB])->save();
    strikes($this->member, 3);
    judgeBoth();
    expect(standingNow($this->member)->state)->toBe(StandingState::Restricted);

    $this->travelTo('2026-11-03 00:00:00');
    HitAndRunStrike::query()->where('user_id', $this->member->id)->update(['cleared_at' => now()]);
    judgeBoth();

    expect(standingNow($this->member)->state)->toBe(StandingState::Watch)
        ->and(standingNow($this->member)->until?->toDateString())->toBe('2026-11-15');
});

it('restricts on strikes under ratio_mode seedtime, where the ratio is not enforced', function () {
    config(['bloodhound.ratio_mode' => 'seedtime', 'bloodhound.hit_and_run.enabled' => false]);
    $this->member->forceFill(['uploaded' => 0, 'downloaded' => 50 * BOTH_GIB])->save();
    strikes($this->member, 3);

    judgeBoth();

    expect(standingNow($this->member)->state)->toBe(StandingState::Restricted)
        ->and(standingNow($this->member)->reason)->not->toContain('ratio is');
});

it('never restricts on strikes with hit-and-run off', function () {
    config(['bloodhound.hit_and_run.enabled' => false]);
    strikes($this->member, 5);

    judgeBoth();

    expect(standingNow($this->member)->state)->toBe(StandingState::Ok);
});
