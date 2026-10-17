<?php

declare(strict_types=1);

// Job #193 (design 5.6, 5.8, addendum A1): what a 7.x install brings to 8.0.
//
// 1. Stored seedtime and leechtime no ledger row explains (an import, a hand
//    edit: 7.x itself always wrote 0) become carry, so the "rebuild once"
//    upgrade step no longer erases them (F10).
// 2. bloodhound:backfill-ledger gives 7.x history the time it would have been
//    credited, and the bytes its stops reported, as carry, and adds them to
//    the totals. It never rewrites a ledger row: the ledger stays append-only.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create(['name' => 'Up', 'email' => 'upgrade@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create(['name' => 'Up', 'info_hash' => str_repeat('6', 40), 'size' => 1_000, 'user_id' => $this->user->id]);
    $this->t = Carbon::parse('2026-10-01 10:00:00');
});

function runTimeOpeningBalances(): void
{
    (require __DIR__.'/../../database/migrations/2026_10_18_000002_record_time_opening_balances.php')->up();
}

function userTime(TestUser $user): array
{
    $row = DB::table($user->getTable())->where('id', $user->id)->first();

    return [(int) $row->seedtime, (int) $row->leechtime];
}

describe('stored time becomes carry (F10)', function () {
    // The audit's C4: an opening balance carried bytes only, so reconcile
    // reported the imported seedtime as drift and rebuild zeroed it.
    test('imported seedtime survives the rebuild the upgrade asks for', function () {
        DB::table($this->user->getTable())->where('id', $this->user->id)->update(['seedtime' => 900, 'leechtime' => 50]);
        DB::table('torrent_user')->insert([
            'user_id' => $this->user->id, 'torrent_id' => $this->torrent->id, 'seedtime' => 600,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        runTimeOpeningBalances();

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse()
            ->and(LedgerCarry::query()->where('torrent_id', $this->torrent->id)->value('seedtime'))->toBe(600)
            ->and(LedgerCarry::query()->where('torrent_id', 0)->first()?->only(['seedtime', 'leechtime']))->toBe(['seedtime' => 300, 'leechtime' => 50]);

        app(LedgerRebuilder::class)->rebuild();

        expect(userTime($this->user))->toBe([900, 50])
            ->and(TorrentUser::query()->where('torrent_id', $this->torrent->id)->value('seedtime'))->toBe(600);
    });

    test('running it again records nothing more', function () {
        DB::table($this->user->getTable())->where('id', $this->user->id)->update(['seedtime' => 900]);

        runTimeOpeningBalances();
        runTimeOpeningBalances();

        expect((int) LedgerCarry::query()->sum('seedtime'))->toBe(900);
    });

    test('time the ledger already explains is not carried', function () {
        AnnounceLog::create([
            'user_id' => $this->user->id, 'torrent_id' => $this->torrent->id, 'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'regular', 'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => 0, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => 0, 'download_delta' => 0, 'seed_seconds' => 1_800,
        ]);
        app(LedgerAggregator::class)->run();

        runTimeOpeningBalances();

        expect(LedgerCarry::query()->count())->toBe(0);
    });
});

/**
 * A 7.x row: no seconds, no prior_at, a stop with zero bytes and no baseline.
 */
function legacyRow($test, int $minutes, string $event, int $left, int $uploaded, int $delta = 0, ?int $priorUp = null, string $peer = '-qB4210-aaaaaaaaaaaa'): AnnounceLog
{
    $row = new AnnounceLog;
    $row->forceFill([
        'user_id' => $test->user->id, 'torrent_id' => $test->torrent->id, 'peer_id' => $peer, 'event' => $event,
        'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => $uploaded, 'downloaded' => 0, 'left' => $left,
        'upload_delta' => $delta, 'download_delta' => 0, 'prior_up' => $priorUp, 'prior_down' => $priorUp === null ? null : 0,
        'created_at' => $test->t->copy()->addMinutes($minutes),
    ])->save();

    return $row;
}

/** Mark every row so far as 7.x, and fold them, as an upgraded install would have. */
function asLegacyHistory(): void
{
    DB::table('ledger_cursors')->where('stream', LedgerAuditor::TIME_COLUMNS_FROM)->update(['position' => (int) AnnounceLog::query()->max('id')]);
    app(LedgerAggregator::class)->run();
}

function legacyHistory($test): void
{
    legacyRow($test, 0, 'started', 100, 0);
    legacyRow($test, 30, 'regular', 100, 1_000, delta: 1_000, priorUp: 0);
    legacyRow($test, 60, 'completed', 0, 1_200, delta: 200, priorUp: 1_000);
    legacyRow($test, 90, 'regular', 0, 1_500, delta: 300, priorUp: 1_200);
    // 7.x credited nothing on a stop: 3,500 bytes and 10 minutes, lost.
    legacyRow($test, 100, 'stopped', 0, 5_000);
    // A rejected announce: flagged, credited nothing, never a partner.
    $flagged = legacyRow($test, 300, 'started', 0, 0);
    $flagged->forceFill(['anti_cheat_flagged' => true, 'anti_cheat_reason' => 'Port 6881 is blacklisted'])->save();
    legacyRow($test, 301, 'started', 0, 0);
    legacyRow($test, 331, 'regular', 0, 700, delta: 700, priorUp: 0);
}

describe('bloodhound:backfill-ledger (A1)', function () {
    test('credits 7.x history its time and its stops\' bytes, as carry and on the totals', function () {
        legacyHistory($this);
        asLegacyHistory();
        $rowsBefore = AnnounceLog::query()->orderBy('id')->get()->toArray();

        $this->artisan('bloodhound:backfill-ledger')->assertSuccessful();

        // leech 1800 (0→30) + 1800 (30→60, started leeching); seed 1800 (60→90)
        // + 600 (90→100, the stop) + 1800 (301→331); nothing across the stop.
        $carry = LedgerCarry::query()->where('torrent_id', $this->torrent->id)->firstOrFail();

        expect([$carry->uploaded, $carry->seedtime, $carry->leechtime])->toBe([3_500, 4_200, 3_600])
            ->and(userTime($this->user))->toBe([4_200, 3_600])
            ->and((int) DB::table($this->user->getTable())->where('id', $this->user->id)->value('uploaded'))->toBe(1_000 + 200 + 300 + 3_500 + 700)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse()
            ->and(AnnounceLog::query()->orderBy('id')->get()->toArray())->toBe($rowsBefore);
    });

    test('a second run credits nothing more', function () {
        legacyHistory($this);
        asLegacyHistory();

        $this->artisan('bloodhound:backfill-ledger')->assertSuccessful();
        $this->artisan('bloodhound:backfill-ledger')->assertSuccessful();

        expect(userTime($this->user))->toBe([4_200, 3_600]);
    });

    test('a rebuild afterwards gives the same totals', function () {
        legacyHistory($this);
        asLegacyHistory();
        $this->artisan('bloodhound:backfill-ledger')->assertSuccessful();
        $after = userTime($this->user);

        app(LedgerRebuilder::class)->rebuild();

        expect(userTime($this->user))->toBe($after);
    });

    test('it waits for the aggregator to reach the end of 7.x history', function () {
        legacyHistory($this);
        DB::table('ledger_cursors')->where('stream', LedgerAuditor::TIME_COLUMNS_FROM)->update(['position' => (int) AnnounceLog::query()->max('id')]);

        $this->artisan('bloodhound:backfill-ledger')->assertFailed();

        expect(LedgerCarry::query()->count())->toBe(0);
    });

    test('rows written by 8.0 are left alone', function () {
        legacyRow($this, 0, 'started', 0, 0);
        legacyRow($this, 30, 'regular', 0, 100, delta: 100, priorUp: 0);
        app(LedgerAggregator::class)->run();

        $this->artisan('bloodhound:backfill-ledger')->assertSuccessful();

        expect(LedgerCarry::query()->count())->toBe(0);
    });
});
