<?php

declare(strict_types=1);

// Job #112 CP1 (Spec #121): the one place that knows how each engine measures
// time between two announces.
//
// The SQL-text tests pin the per-driver fragments, because only one engine
// runs per suite and the other three would otherwise go unexamined until
// tools/test-engines. The fixture tests execute the real query on whichever
// engine IS running — which, across the four-engine run, is all of them.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Support\SessionTime;

describe('the engine fragments', function () {
    test('SQLite diffs epoch seconds and clamps with MIN/MAX', function () {
        $time = new SessionTime('sqlite', 3600);

        expect($time->secondsBetween('a', 'b'))->toBe("(strftime('%s', b) - strftime('%s', a))")
            ->and($time->credited('a', 'b'))->toBe("MAX(0, MIN((strftime('%s', b) - strftime('%s', a)), 3600))");
    });

    test('MySQL and MariaDB use TIMESTAMPDIFF and LEAST/GREATEST', function (string $driver) {
        $time = new SessionTime($driver, 3600);

        expect($time->secondsBetween('a', 'b'))->toBe('TIMESTAMPDIFF(SECOND, a, b)')
            ->and($time->credited('a', 'b'))->toBe('GREATEST(0, LEAST(TIMESTAMPDIFF(SECOND, a, b), 3600))');
    })->with(['mysql', 'mariadb']);

    test('PostgreSQL extracts the epoch of the interval', function () {
        $time = new SessionTime('pgsql', 3600);

        expect($time->secondsBetween('a', 'b'))->toBe('CAST(EXTRACT(EPOCH FROM (b - a)) AS BIGINT)')
            ->and($time->credited('a', 'b'))->toBe('GREATEST(0, LEAST(CAST(EXTRACT(EPOCH FROM (b - a)) AS BIGINT), 3600))');
    });

    // An engine Marque does not support gets told so, rather than handed SQL
    // written for a different one and left to decode the syntax error.
    test('an unsupported driver is refused by name', function () {
        new SessionTime('sqlsrv', 3600);
    })->throws(InvalidArgumentException::class, 'sqlsrv');

    test('the cap comes from threepio.peer_expiry', function () {
        config()->set('threepio.peer_expiry', 1234);

        expect(SessionTime::forLedger()->cap())->toBe(1234);
    });
});

/**
 * Log one announce for a peer, at a given time.
 */
function logAnnounce(string $event, int $left, Carbon $at, string $peerId = '-qB4210-aaaaaaaaaaaa', int $userId = 1): void
{
    $row = AnnounceLog::create([
        'user_id' => $userId,
        'torrent_id' => 1,
        'peer_id' => $peerId,
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => $left,
        'upload_delta' => 0,
        'download_delta' => 0,
    ]);

    // created_at is useCurrent(); the fixture needs controlled spacing.
    $row->forceFill(['created_at' => $at])->save();
}

/**
 * Sum what the intervals query credits, per user.
 *
 * @return array{seed: int, leech: int}
 */
function creditedFor(int $userId = 1): array
{
    $row = SessionTime::forLedger()->intervals()
        ->where('user_id', $userId)
        ->selectRaw('SUM(seed_seconds) as seed, SUM(leech_seconds) as leech')
        ->first();

    return ['seed' => (int) $row->seed, 'leech' => (int) $row->leech];
}

describe('the intervals query', function () {
    // The fixture Spec #121 OQ2 verified by hand on all four engines.
    test('leech, leech, seed, seed, stopped at 30-minute spacing is 3600s each', function () {
        $t = Carbon::parse('2026-10-01 10:00:00');

        logAnnounce('started', 1_000, $t);
        logAnnounce('regular', 500, $t->copy()->addMinutes(30));
        logAnnounce('completed', 0, $t->copy()->addMinutes(60));
        logAnnounce('regular', 0, $t->copy()->addMinutes(90));
        logAnnounce('stopped', 0, $t->copy()->addMinutes(120));

        // leech->leech (1800) and leech->seed (1800, the completing interval,
        // attributed by its START) are leech; seed->seed and seed->stopped are
        // seed.
        expect(creditedFor())->toBe(['seed' => 3600, 'leech' => 3600]);
    });

    test('a gap past the cap credits the cap, not the gap and not zero', function () {
        $t = Carbon::parse('2026-10-01 10:00:00');

        logAnnounce('started', 0, $t);
        logAnnounce('regular', 0, $t->copy()->addHours(6));

        expect(creditedFor())->toBe(['seed' => 3600, 'leech' => 0]);
    });

    test('nothing is credited from a stopped row to the next announce', function () {
        $t = Carbon::parse('2026-10-01 10:00:00');

        logAnnounce('stopped', 0, $t);
        logAnnounce('started', 0, $t->copy()->addHours(6));

        expect(creditedFor())->toBe(['seed' => 0, 'leech' => 0]);
    });

    // Rows are ordered by id. If a clock steps backwards between two
    // announces, the diff is negative — and adding a negative to an unsigned
    // column fails on MySQL rather than merely being wrong.
    test('a negative gap credits zero', function () {
        $t = Carbon::parse('2026-10-01 10:00:00');

        logAnnounce('started', 0, $t);
        logAnnounce('regular', 0, $t->copy()->subMinutes(10));

        expect(creditedFor())->toBe(['seed' => 0, 'leech' => 0]);
    });

    // Two clients on one torrent are two sessions. Pairing across them would
    // credit the gap between one client's announce and the other's.
    test('intervals pair within a peer, never across peers', function () {
        $t = Carbon::parse('2026-10-01 10:00:00');

        logAnnounce('started', 0, $t, '-qB4210-aaaaaaaaaaaa');
        logAnnounce('started', 0, $t->copy()->addMinutes(10), '-TR3000-bbbbbbbbbbbb');
        logAnnounce('regular', 0, $t->copy()->addMinutes(30), '-qB4210-aaaaaaaaaaaa');

        expect(creditedFor())->toBe(['seed' => 1800, 'leech' => 0]);
    });

    // An opening balance carries pre-ledger bytes forward; it is not an
    // announce and says nothing about when anyone was connected.
    test('opening-balance rows contribute no time', function () {
        $t = Carbon::parse('2026-10-01 10:00:00');

        logAnnounce(AnnounceLog::EVENT_OPENING_BALANCE, 0, $t, '');
        logAnnounce(AnnounceLog::EVENT_OPENING_BALANCE, 0, $t->copy()->addMinutes(30), '');

        expect(creditedFor())->toBe(['seed' => 0, 'leech' => 0]);
    });

    // Each interval is credited on its CLOSING row, so a consumer folding rows
    // by id range credits it exactly once, whichever side of a batch boundary
    // the two rows fall on.
    test('each interval is credited on the row that closes it', function () {
        $t = Carbon::parse('2026-10-01 10:00:00');

        logAnnounce('started', 0, $t);
        logAnnounce('regular', 0, $t->copy()->addMinutes(30));

        $rows = SessionTime::forLedger()->intervals()->orderBy('id')->get();

        expect((int) $rows[0]->seed_seconds)->toBe(0)
            ->and((int) $rows[1]->seed_seconds)->toBe(1800);
    });

    test('the ledger connection decides the engine', function () {
        expect(SessionTime::forLedger()->driver())
            ->toBe(DB::connection((new AnnounceLog)->getConnectionName())->getDriverName());
    });
});
