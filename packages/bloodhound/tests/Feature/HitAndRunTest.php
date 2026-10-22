<?php

declare(strict_types=1);

// Job #256: the hit-and-run rule. A torrent a member downloaded more than the
// buffer of carries an obligation: seed it min_seedtime (72h) in all, or reach
// hit_and_run.ratio (1.0) on it, whichever first. Away from it while short:
// a pre-warning after prewarn_days, a strike after grace_days. Seeding it to
// the requirement clears the strike; a strike lapses after expire_days. The
// defaults are UNIT3D's mechanics, with 72h rather than its 7 days.

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Events\HitAndRunCleared;
use Marque\Bloodhound\Events\HitAndRunPrewarned;
use Marque\Bloodhound\Events\HitAndRunStrike;
use Marque\Bloodhound\Models\HitAndRunStrike as StrikeRow;
use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\HitAndRunEnforcer;
use Marque\Trove\Models\Torrent;

const HNR_GIB = 1024 ** 3;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config([
        'bloodhound.ratio_mode' => 'full',
        'bloodhound.min_seedtime' => 72 * 3600,
        'bloodhound.hit_and_run' => [
            'enabled' => true, 'ratio' => 1.0, 'buffer_percent' => 10,
            'prewarn_days' => 1, 'grace_days' => 3, 'expire_days' => 14, 'max_strikes' => 3,
        ],
        'bloodhound.anti_cheat.enabled' => false,
    ]);
    Event::fake([HitAndRunPrewarned::class, HitAndRunStrike::class, HitAndRunCleared::class]);

    // The rule starts counting from its first run: activity before it is
    // never an obligation.
    $this->travelTo('2026-11-01 00:00:00');
    hnr();

    static $n = 0;
    $n++;
    $this->member = makeTrackerUser(str_pad("hnr{$n}", 32, 'h'), "hnr{$n}@example.com");
    $this->torrent = Torrent::create(['name' => 'Owed', 'info_hash' => str_pad((string) $n, 40, 'e'), 'size' => 10 * HNR_GIB, 'user_id' => $this->member->id]);
});

function hnr(): array
{
    return app(HitAndRunEnforcer::class)->run();
}

/** A member's activity on the torrent, as the ledger would have folded it, last seen at $seen. */
function owe(object $test, int $downloaded, int $seedtime = 0, int $uploaded = 0, string $seen = '2026-11-02 00:00:00', ?string $first = null): TorrentUser
{
    $test->travelTo($first ?? $seen);

    return TorrentUser::query()->forceCreate([
        'user_id' => $test->member->id,
        'torrent_id' => $test->torrent->id,
        'uploaded' => $uploaded,
        'downloaded' => $downloaded,
        'seedtime' => $seedtime,
        'leechtime' => 0,
        'times_completed' => 1,
        'last_seen' => Carbon::parse($seen)->getTimestamp(),
    ]);
}

function strikeRow(object $test): ?StrikeRow
{
    return StrikeRow::query()->where('user_id', $test->member->id)->where('torrent_id', $test->torrent->id)->first();
}

describe('the obligation', function () {
    it('pre-warns a member a day away from a torrent they owe, then strikes them at three', function () {
        owe($this, downloaded: 10 * HNR_GIB, seedtime: 3600);

        $this->travelTo('2026-11-03 01:00:00');
        hnr();
        expect(strikeRow($this)?->prewarned_at)->not->toBeNull()
            ->and(strikeRow($this)->struck_at)->toBeNull();
        Event::assertDispatchedTimes(HitAndRunPrewarned::class, 1);

        $this->travelTo('2026-11-05 01:00:00');
        hnr();
        expect(strikeRow($this)->struck_at?->toDateTimeString())->toBe('2026-11-05 01:00:00')
            ->and(strikeRow($this)->expires_at?->toDateTimeString())->toBe('2026-11-19 01:00:00');
        Event::assertDispatchedTimes(HitAndRunStrike::class, 1);
    });

    it('never strikes without a pre-warning at least a day old, however long they have been away', function () {
        owe($this, downloaded: 10 * HNR_GIB, seen: '2026-11-02 00:00:00');

        $this->travelTo('2026-11-20 00:00:00');
        hnr();
        $this->travelTo('2026-11-20 01:00:00');
        hnr();

        expect(strikeRow($this)->struck_at)->toBeNull();
        Event::assertNotDispatched(HitAndRunStrike::class);
    });

    it('ignores a torrent the member downloaded no more than the buffer of', function () {
        owe($this, downloaded: HNR_GIB); // 10% of 10 GiB: not more than the buffer

        $this->travelTo('2026-11-10 00:00:00');
        hnr();

        expect(strikeRow($this))->toBeNull();
    });

    it('is met by seeding the required time', function () {
        owe($this, downloaded: 10 * HNR_GIB, seedtime: 72 * 3600);

        $this->travelTo('2026-11-10 00:00:00');
        hnr();

        expect(strikeRow($this))->toBeNull();
    });

    it('is met by a 1:1 ratio on the torrent, unless the rule is time only', function () {
        owe($this, downloaded: 10 * HNR_GIB, uploaded: 10 * HNR_GIB);
        $this->travelTo('2026-11-10 00:00:00');
        hnr();
        expect(strikeRow($this))->toBeNull();

        config(['bloodhound.hit_and_run.ratio' => null]);
        hnr();
        expect(strikeRow($this)?->prewarned_at)->not->toBeNull();
    });

    it('never counts a member who is seeding the torrent right now', function () {
        owe($this, downloaded: 10 * HNR_GIB, seen: '2026-11-02 00:00:00');
        $this->travelTo('2026-11-10 00:00:00');
        announceAt($this->member, $this->torrent, now(), left: 0, event: 'started');

        hnr();

        expect(strikeRow($this))->toBeNull();
    });

    it('forgets a pre-warning once the member is back', function () {
        owe($this, downloaded: 10 * HNR_GIB, seen: '2026-11-02 00:00:00');
        $this->travelTo('2026-11-03 01:00:00');
        hnr();

        TorrentUser::query()->where('user_id', $this->member->id)->update(['last_seen' => now()->getTimestamp()]);
        hnr();

        expect(strikeRow($this))->toBeNull();
    });

    it('never makes an obligation of activity from before the rule began', function () {
        owe($this, downloaded: 10 * HNR_GIB, seen: '2026-10-20 00:00:00', first: '2026-10-20 00:00:00');

        $this->travelTo('2026-11-20 00:00:00');
        hnr();

        expect(strikeRow($this))->toBeNull();
    });
});

describe('a strike', function () {
    beforeEach(function () {
        owe($this, downloaded: 10 * HNR_GIB, seedtime: 3600);
        $this->travelTo('2026-11-03 01:00:00');
        hnr();
        $this->travelTo('2026-11-05 01:00:00');
        hnr();
    });

    it('clears once the member seeds the torrent to the requirement', function () {
        TorrentUser::query()->where('user_id', $this->member->id)->update(['seedtime' => 72 * 3600]);
        $this->travelTo('2026-11-06 01:00:00');
        hnr();

        expect(strikeRow($this)->cleared_at?->toDateTimeString())->toBe('2026-11-06 01:00:00')
            ->and(app(HitAndRunEnforcer::class)->activeStrikes($this->member->id))->toBe(0);
        Event::assertDispatchedTimes(HitAndRunCleared::class, 1);
    });

    it('lapses after the expiry, and the torrent is never struck again', function () {
        expect(app(HitAndRunEnforcer::class)->activeStrikes($this->member->id))->toBe(1);

        $this->travelTo('2026-11-19 01:00:00');
        hnr();
        $this->travelTo('2026-12-30 00:00:00');
        hnr();

        expect(app(HitAndRunEnforcer::class)->activeStrikes($this->member->id))->toBe(0);
        Event::assertDispatchedTimes(HitAndRunStrike::class, 1);
    });
});

describe('who it applies to', function () {
    it('skips staff at or above the exempt role and members an admin exempted', function () {
        $this->member->forceFill(['role' => 'moderator'])->save();
        owe($this, downloaded: 10 * HNR_GIB);
        $this->travelTo('2026-11-10 00:00:00');
        hnr();
        expect(strikeRow($this))->toBeNull();

        $this->member->forceFill(['role' => 'user'])->save();
        MemberStanding::query()->create(['user_id' => $this->member->id, 'state' => 'ok', 'exempted_at' => now(), 'exempt_reason' => 'Donor']);
        hnr();
        expect(strikeRow($this))->toBeNull();
    });

    it('applies under ratio_mode seedtime even with hit_and_run off, and never under off', function () {
        owe($this, downloaded: 10 * HNR_GIB);
        config(['bloodhound.hit_and_run.enabled' => false, 'bloodhound.ratio_mode' => 'seedtime']);
        $this->travelTo('2026-11-10 00:00:00');
        hnr();
        expect(strikeRow($this)?->prewarned_at)->not->toBeNull();

        config(['bloodhound.ratio_mode' => 'off', 'bloodhound.hit_and_run.enabled' => true]);
        expect(app(HitAndRunEnforcer::class)->applies())->toBeFalse();
    });

    it('does nothing when it is off', function () {
        config(['bloodhound.hit_and_run.enabled' => false]);
        owe($this, downloaded: 10 * HNR_GIB);
        $this->travelTo('2026-11-10 00:00:00');
        hnr();

        expect(strikeRow($this))->toBeNull();
    });

    it('starts afresh when switched off and on again', function () {
        config(['bloodhound.hit_and_run.enabled' => false]);
        $this->travelTo('2026-11-05 00:00:00');
        hnr();
        owe($this, downloaded: 10 * HNR_GIB, seen: '2026-11-06 00:00:00', first: '2026-11-06 00:00:00');

        config(['bloodhound.hit_and_run.enabled' => true]);
        $this->travelTo('2026-11-08 00:00:00');
        hnr();

        // First active after the old start but before the new one.
        expect(strikeRow($this))->toBeNull();
    });
});

describe('the command', function () {
    it('runs hourly on the scheduler', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'bloodhound:enforce-hit-and-run'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *');
    });

    it('reports what it did', function () {
        owe($this, downloaded: 10 * HNR_GIB);
        $this->travelTo('2026-11-03 01:00:00');

        $this->artisan('bloodhound:enforce-hit-and-run')
            ->expectsOutputToContain('1 pre-warned')
            ->assertSuccessful();
    });
});
