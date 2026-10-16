<?php

declare(strict_types=1);

// Job #187 CP4 (#11021): installs that pruned before pruning carried anything
// forward.
//
// Their aggregated rows were deleted with nothing kept, so the ledger cannot
// explain the totals they credited, and reconcile reports every such user as
// drift. Dan's call (option a): record the gap as carry, the stance Spec #99
// took for pre-ledger totals: correct as of now, not proven right.
//
// Only gaps pruning could have made are recorded: per (user, torrent), and
// only where the stored total EXCEEDS the ledger. Pruning removes credit; it
// never adds it, and it never leaves the user total and its torrent rows
// disagreeing. Anything else is real drift and stays reported.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Services\PrunedHistory;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create(['name' => 'A', 'email' => 'hist-a@example.com', 'password' => 'p']);
    $this->other = TestUser::create(['name' => 'B', 'email' => 'hist-b@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create([
        'name' => 'One', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
    $this->second = Torrent::create([
        'name' => 'Two', 'info_hash' => str_repeat('b', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
});

function historyRow(TestUser $user, Torrent $torrent, int $daysAgo, int $up, int $down = 0, string $peer = '-qB4210-aaaaaaaaaaaa'): void
{
    $row = AnnounceLog::create([
        'user_id' => $user->id, 'torrent_id' => $torrent->id, 'peer_id' => $peer, 'event' => 'regular',
        'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => $up, 'downloaded' => $down, 'left' => 0,
        'upload_delta' => $up, 'download_delta' => $down,
    ]);

    $row->forceFill(['created_at' => Carbon::now()->subDays($daysAgo)])->save();
}

/**
 * What a prune did before #11021: delete aggregated aged rows, keep nothing.
 */
function oldStylePrune(int $olderThanDays): void
{
    AnnounceLog::query()->where('created_at', '<', Carbon::now()->subDays($olderThanDays))->delete();
}

function byteSnapshot(TestUser ...$users): array
{
    $snapshot = [];

    foreach ($users as $user) {
        $row = DB::table($user->getTable())->where('id', $user->id)->first();
        $snapshot["user:{$user->id}"] = [(int) $row->uploaded, (int) $row->downloaded];
    }

    foreach (TorrentUser::query()->orderBy('user_id')->orderBy('torrent_id')->get() as $row) {
        $snapshot["torrent_user:{$row->user_id}:{$row->torrent_id}"] = [$row->uploaded, $row->downloaded];
    }

    return $snapshot;
}

/**
 * A history some of which an old-style prune has already destroyed.
 */
function prunedTheOldWay($test): array
{
    historyRow($test->user, $test->torrent, 100, up: 4_000, down: 1_000);
    historyRow($test->user, $test->second, 90, up: 2_500, peer: '-qB4210-cccccccccccc');
    historyRow($test->other, $test->torrent, 95, up: 700, peer: '-TR3000-bbbbbbbbbbbb');
    historyRow($test->user, $test->torrent, 5, up: 300);
    historyRow($test->other, $test->torrent, 2, up: 50, peer: '-TR3000-bbbbbbbbbbbb');
    app(LedgerAggregator::class)->run();

    $before = byteSnapshot($test->user, $test->other);
    oldStylePrune(30);

    return $before;
}

test('the damage is real before anything is recorded', function () {
    prunedTheOldWay($this);

    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeTrue();
});

test('recording pruned history makes reconcile clean and rebuild exact', function () {
    $before = prunedTheOldWay($this);

    app(PrunedHistory::class)->record();

    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    app(LedgerRebuilder::class)->rebuild();

    expect(byteSnapshot($this->user, $this->other))->toBe($before);
});

test('it says what it recorded', function () {
    prunedTheOldWay($this);

    $recorded = app(PrunedHistory::class)->record();

    expect($recorded)->toBe([
        'rows' => 3,
        'users' => 2,
        'uploaded' => 4_000 + 2_500 + 700,
        'downloaded' => 1_000,
    ]);
});

// Pruning removes credit. A stored total BELOW its ledger cannot be pruning,
// so it is not papered over.
test('a stored total below the ledger is left reported, not recorded', function () {
    prunedTheOldWay($this);
    TorrentUser::query()->where('user_id', $this->other->id)->update(['uploaded' => 10]);
    DB::table($this->other->getTable())->where('id', $this->other->id)->update(['uploaded' => 10]);

    app(PrunedHistory::class)->record();

    $report = app(LedgerAuditor::class)->reconcile();

    expect(LedgerCarry::query()->where('user_id', $this->other->id)->exists())->toBeFalse()
        ->and(collect($report->userDrift)->pluck('user_id')->all())->toBe([$this->other->id]);
});

// Pruning never makes a user's total disagree with its own torrent rows: the
// aggregator writes both from the same rows. A gap only at the user level is
// drift between the projections, and stays reported.
test('a gap at the user level alone is left reported', function () {
    prunedTheOldWay($this);
    app(PrunedHistory::class)->record();
    DB::table($this->user->getTable())->where('id', $this->user->id)->increment('uploaded', 999);

    app(PrunedHistory::class)->record();

    expect(app(LedgerAuditor::class)->reconcile()->userDrift)->toHaveCount(1);
});

test('recording twice changes nothing the second time', function () {
    prunedTheOldWay($this);

    app(PrunedHistory::class)->record();
    $carry = LedgerCarry::query()->orderBy('user_id')->orderBy('torrent_id')->get()->toArray();

    $second = app(PrunedHistory::class)->record();

    expect($second['rows'])->toBe(0)
        ->and(LedgerCarry::query()->orderBy('user_id')->orderBy('torrent_id')->get()->toArray())->toBe($carry);
});

// Seedtime was never credited before Job #112, so no stored time can be
// pruned history.
test('time is not recorded', function () {
    prunedTheOldWay($this);
    TorrentUser::query()->update(['seedtime' => 5_000]);

    app(PrunedHistory::class)->record();

    expect((int) LedgerCarry::query()->sum('seedtime'))->toBe(0);
});

describe('the migration', function () {
    // An install that never pruned has no pruned history, so any gap it has
    // is real drift and must not be recorded as carry.
    test('does nothing where no retention is configured', function () {
        prunedTheOldWay($this);
        config(['bloodhound.announce_log.retention_days' => null]);

        (require __DIR__.'/../../database/migrations/2026_10_16_000002_record_pruned_history.php')->up();

        expect(LedgerCarry::query()->count())->toBe(0);
    });

    test('records pruned history where retention is configured', function () {
        prunedTheOldWay($this);
        config(['bloodhound.announce_log.retention_days' => 30]);

        (require __DIR__.'/../../database/migrations/2026_10_16_000002_record_pruned_history.php')->up();

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });
});

test('the command records it on demand, for installs that pruned once and stopped', function () {
    prunedTheOldWay($this);
    config(['bloodhound.announce_log.retention_days' => null]);

    $this->artisan('bloodhound:record-pruned-history')
        ->expectsOutputToContain('3 per-torrent total(s) for 2 user(s)')
        ->assertSuccessful();

    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
});
