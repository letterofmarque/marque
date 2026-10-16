<?php

declare(strict_types=1);

// Job #187 CP2 (#11021): pruning carries forward what it deletes.
//
// Before this, a prune deleted aggregated rows and kept nothing. Reconcile
// read every pruned user as drift, said "run bloodhound:rebuild-totals", and
// the rebuild erased the pruned credit: real users' ratios went down. Now the
// prune derives what the affected peers' rows produce, deletes, derives again,
// and carries the difference, in one transaction.
//
// Each test is built so the old prune fails it.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Services\OpeningBalance;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
    config(['bloodhound.announce_log.retention_days' => 30]);

    $this->user = TestUser::create(['name' => 'A', 'email' => 'prune-a@example.com', 'password' => 'p']);
    $this->other = TestUser::create(['name' => 'B', 'email' => 'prune-b@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create([
        'name' => 'One', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
    $this->second = Torrent::create([
        'name' => 'Two', 'info_hash' => str_repeat('b', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);

    // The retention line: rows created before this are pruned.
    $this->cutoff = Carbon::now()->subDays(30);
});

/**
 * One announce, at a time relative to the retention cutoff.
 */
function pruneRow($test, TestUser $user, Torrent|int $torrent, string $event, int $left, int $minutesFromCutoff, int $up = 0, int $down = 0, string $peer = '-qB4210-aaaaaaaaaaaa'): AnnounceLog
{
    $row = AnnounceLog::create([
        'user_id' => $user->id,
        'torrent_id' => $torrent instanceof Torrent ? $torrent->id : $torrent,
        'peer_id' => $peer,
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => $up,
        'downloaded' => $down,
        'left' => $left,
        'upload_delta' => $up,
        'download_delta' => $down,
    ]);

    $row->forceFill(['created_at' => $test->cutoff->copy()->addMinutes($minutesFromCutoff)])->save();

    return $row;
}

/**
 * A history straddling the cutoff: two users, two torrents, two peers,
 * a completion, a stop, and intervals that cross the line.
 */
function straddlingHistory($test): void
{
    pruneRow($test, $test->user, $test->torrent, 'started', 1_000, -300);
    pruneRow($test, $test->user, $test->torrent, 'regular', 400, -270, down: 600);
    pruneRow($test, $test->other, $test->torrent, 'started', 0, -260, peer: '-TR3000-bbbbbbbbbbbb');
    pruneRow($test, $test->user, $test->torrent, 'completed', 0, -240, up: 50, down: 400);
    pruneRow($test, $test->other, $test->torrent, 'regular', 0, -230, up: 7_000, peer: '-TR3000-bbbbbbbbbbbb');
    pruneRow($test, $test->user, $test->second, 'started', 0, -40, peer: '-qB4210-cccccccccccc');
    pruneRow($test, $test->user, $test->torrent, 'regular', 0, -10, up: 900);
    pruneRow($test, $test->user, $test->second, 'regular', 0, -10, up: 30, peer: '-qB4210-cccccccccccc');
    pruneRow($test, $test->user, $test->torrent, 'regular', 0, 20, up: 300);
    pruneRow($test, $test->other, $test->torrent, 'regular', 0, 25, up: 100, peer: '-TR3000-bbbbbbbbbbbb');
    pruneRow($test, $test->user, $test->second, 'stopped', 0, 20, peer: '-qB4210-cccccccccccc');
}

/**
 * Every figure both projections hold, for every user.
 *
 * @return array<string, array<int, int>>
 */
function pruneSnapshot($test): array
{
    $snapshot = [];

    foreach ([$test->user, $test->other] as $user) {
        $row = DB::table($user->getTable())->where('id', $user->id)->first();
        $snapshot["user:{$user->id}"] = [(int) $row->uploaded, (int) $row->downloaded, (int) $row->seedtime, (int) $row->leechtime];
    }

    foreach (TorrentUser::query()->orderBy('user_id')->orderBy('torrent_id')->get() as $row) {
        $snapshot["torrent_user:{$row->user_id}:{$row->torrent_id}"] = [$row->uploaded, $row->downloaded, $row->seedtime, $row->leechtime];
    }

    return $snapshot;
}

function prune($test): void
{
    $test->artisan('bloodhound:prune-announce-log')->assertSuccessful();
}

test('the prune deletes the aged rows it always did', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();

    prune($this);

    expect(AnnounceLog::query()->where('created_at', '<', $this->cutoff)->count())->toBe(0)
        ->and(AnnounceLog::query()->count())->toBe(3);
});

// Criterion 1.
test('after a prune, reconcile reports no drift', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    prune($this);

    $report = app(LedgerAuditor::class)->reconcile();

    expect($report->userDrift)->toBe([])
        ->and($report->torrentUserDrift)->toBe([]);
});

// Criterion 2. The one #11021 is about: the rebuild reconcile recommends must
// give back what people had, not what survived the prune.
test('after a prune, a full rebuild reproduces the pre-prune totals', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    prune($this);
    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this))->toBe($before);
});

test('after a prune, a scoped rebuild reproduces that user\'s totals', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    prune($this);
    app(LedgerRebuilder::class)->rebuild($this->user->id);
    app(LedgerRebuilder::class)->rebuild($this->other->id);

    expect(pruneSnapshot($this))->toBe($before);
});

// Criterion 3. The interval from a pruned announce to a surviving one is
// credited on the SURVIVING row. Carrying only what the deleted rows were
// credited would lose it: after the prune the survivor has no partner.
test('an interval from a pruned announce to a surviving one keeps its credit', function () {
    pruneRow($this, $this->user, $this->torrent, 'started', 0, -10);
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, 10);
    app(LedgerAggregator::class)->run();

    expect(pruneSnapshot($this)["user:{$this->user->id}"])->toBe([0, 0, 1200, 0]);

    prune($this);
    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this)["user:{$this->user->id}"])->toBe([0, 0, 1200, 0])
        // SUM() comes back as a string on MySQL, MariaDB and PostgreSQL.
        ->and((int) LedgerCarry::query()->sum('seedtime'))->toBe(1200);
});

// Criterion 4. Delete and carry are one fact; neither may land alone.
test('a failure while carrying leaves the rows undeleted and nothing carried', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    $rows = AnnounceLog::query()->count();

    LedgerCarry::saving(fn () => throw new RuntimeException('carry write failed'));

    expect(fn () => $this->artisan('bloodhound:prune-announce-log'))->toThrow(RuntimeException::class);

    expect(AnnounceLog::query()->count())->toBe($rows)
        ->and(LedgerCarry::query()->count())->toBe(0);
});

// Criterion 5.
test('successive prunes accumulate carry, and rebuild stays exact', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    config(['bloodhound.announce_log.retention_days' => 30]);
    $this->cutoff = Carbon::now()->subDays(30)->subMinutes(250);
    $this->travelTo(Carbon::parse('2026-10-16 12:00:00')->subMinutes(250));
    prune($this);

    $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
    prune($this);

    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this))->toBe($before);
});

// Criterion 6. An opening balance is the oldest row an upgraded install has;
// it is the first thing retention reaches.
test('an opening balance can be pruned without losing it', function () {
    DB::table($this->user->getTable())->where('id', $this->user->id)->update(['uploaded' => 50_000, 'downloaded' => 8_000]);
    app(OpeningBalance::class)->record();
    AnnounceLog::query()->update(['created_at' => $this->cutoff->copy()->subDays(5)]);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    prune($this);

    expect(AnnounceLog::query()->where('event', AnnounceLog::EVENT_OPENING_BALANCE)->count())->toBe(0)
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this))->toBe($before);
});

// Criterion 7.
test('pruning introduces no chain-audit breaks', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();

    prune($this);

    expect(app(LedgerAuditor::class)->audit()->chainBreaks)->toBe([]);
});

// The floor, unchanged: rows the aggregator has not consumed are never pruned,
// so nothing is carried for them either.
test('unaggregated aged rows are kept and carry nothing', function () {
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, -60, up: 500);

    prune($this);

    expect(AnnounceLog::query()->count())->toBe(1)
        ->and(LedgerCarry::query()->count())->toBe(0);
});

// Rows pair by id. If a clock stepped back, a pruned row can sit between two
// survivors in id order while being older than both. Deleting it pairs the
// survivors directly, which can credit MORE than before, so the carry for
// that pair is negative: an adjustment, not a balance. Unsigned columns would
// refuse the write and the prune would fail.
test('a prune that pairs the survivors differently carries a negative adjustment', function () {
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, 0);
    pruneRow($this, $this->user, $this->torrent, 'stopped', 0, -20);
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, 30);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    prune($this);

    expect(LedgerCarry::query()->value('seedtime'))->toBe(-1800)
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this))->toBe($before);
});

// Correction to CP #1021, found by the independent check. Time is credited on
// the announce that CLOSES an interval. If a prune deletes a peer's last
// aggregated announce while its next one is still waiting to be aggregated,
// or before it arrives at all, that next announce has nothing to pair with and
// its interval is never credited, and reconcile and rebuild agree on the loss,
// so nothing reports it. So a peer's latest aggregated announce is kept.
describe('each peer\'s latest aggregated announce', function () {
    test('is kept, so an announce still in the backlog keeps its interval', function () {
        pruneRow($this, $this->user, $this->torrent, 'regular', 0, -10);
        app(LedgerAggregator::class)->run();
        pruneRow($this, $this->user, $this->torrent, 'regular', 0, 20);

        prune($this);
        app(LedgerAggregator::class)->run();

        expect(pruneSnapshot($this)["user:{$this->user->id}"])->toBe([0, 0, 1800, 0])
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

        $before = pruneSnapshot($this);
        app(LedgerRebuilder::class)->rebuild();

        expect(pruneSnapshot($this))->toBe($before);
    });

    // The cap applies to a gap of any length (Spec #121): a peer that went
    // quiet without stopping and comes back is credited up to peer_expiry.
    test('is kept, so a peer returning after the retention window is credited the capped gap', function () {
        pruneRow($this, $this->user, $this->torrent, 'regular', 0, -60 * 24 * 10);
        app(LedgerAggregator::class)->run();

        prune($this);
        pruneRow($this, $this->user, $this->torrent, 'regular', 0, 60 * 24 * 29);
        app(LedgerAggregator::class)->run();

        expect(pruneSnapshot($this)["user:{$this->user->id}"])->toBe([0, 0, 3600, 0]);
    });

    test('is the only row of that peer kept; the older ones are pruned and carried', function () {
        pruneRow($this, $this->user, $this->torrent, 'regular', 0, -90, up: 100);
        pruneRow($this, $this->user, $this->torrent, 'regular', 0, -60, up: 200);
        pruneRow($this, $this->user, $this->torrent, 'regular', 0, -30, up: 300);
        app(LedgerAggregator::class)->run();
        $before = pruneSnapshot($this);

        prune($this);

        expect(AnnounceLog::query()->pluck('upload_delta')->all())->toBe([300])
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

        app(LedgerRebuilder::class)->rebuild();

        expect(pruneSnapshot($this))->toBe($before);
    });

    // Nothing is ever credited from a `stopped` row, so keeping one would
    // protect nothing. Most sessions end with a stop, so most peers still
    // prune to nothing.
    test('is not kept when it is a stop', function () {
        pruneRow($this, $this->user, $this->torrent, 'regular', 0, -60);
        pruneRow($this, $this->user, $this->torrent, 'stopped', 0, -30);
        app(LedgerAggregator::class)->run();

        prune($this);

        expect(AnnounceLog::query()->count())->toBe(0);
    });
});
