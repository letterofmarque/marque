<?php

declare(strict_types=1);

// Job #187 CP1 (#11021): ledger_carry holds credit whose ledger rows are gone,
// and reconcile and rebuild count it.
//
// Pruning deleted aggregated rows and carried nothing forward. Reconcile then
// read every pruned user as drift and told the operator to rebuild, and the
// rebuild erased the pruned credit. Carry is that credit, kept: the ledger is
// now its rows PLUS its carry, and both checks read it that way.
//
// Carry is seeded directly here, the state a prune leaves behind; CP2 makes
// the prune write it.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create(['name' => 'A', 'email' => 'carry-a@example.com', 'password' => 'p']);
    $this->other = TestUser::create(['name' => 'B', 'email' => 'carry-b@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create([
        'name' => 'One', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
    $this->second = Torrent::create([
        'name' => 'Two', 'info_hash' => str_repeat('b', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
    $this->t = Carbon::parse('2026-10-01 10:00:00');
});

function carryRow($test, TestUser $user, Torrent $torrent, int $minutes, int $up = 0, string $event = 'regular'): void
{
    $row = AnnounceLog::create([
        'user_id' => $user->id,
        'torrent_id' => $torrent->id,
        'peer_id' => '-qB4210-'.str_pad((string) $user->id, 12, '0', STR_PAD_LEFT),
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => $up,
        'downloaded' => 0,
        'left' => 0,
        'upload_delta' => $up,
        'download_delta' => 0,
    ]);

    $row->forceFill(['created_at' => $test->t->copy()->addMinutes($minutes)])->save();
}

/**
 * Credit the projections hold beyond what the remaining rows explain, and
 * record it as carry: the state a carrying prune leaves.
 */
function carried(TestUser $user, Torrent $torrent, int $uploaded = 0, int $seedtime = 0, int $leechtime = 0): void
{
    LedgerCarry::query()->create([
        'user_id' => $user->id,
        'torrent_id' => $torrent->id,
        'uploaded' => $uploaded,
        'downloaded' => 0,
        'seedtime' => $seedtime,
        'leechtime' => $leechtime,
    ]);

    $increments = ['uploaded' => $uploaded, 'seedtime' => $seedtime, 'leechtime' => $leechtime];

    DB::table($user->getTable())->where('id', $user->id)->incrementEach($increments);

    $existing = TorrentUser::query()->where('user_id', $user->id)->where('torrent_id', $torrent->id)->first();

    if ($existing === null) {
        TorrentUser::query()->create(['user_id' => $user->id, 'torrent_id' => $torrent->id] + $increments);

        return;
    }

    $existing->forceFill([
        'uploaded' => $existing->uploaded + $uploaded,
        'seedtime' => $existing->seedtime + $seedtime,
        'leechtime' => $existing->leechtime + $leechtime,
    ])->save();
}

/**
 * @return array{user: array{int, int, int}, torrents: array<string, array{int, int, int}>}
 */
function projections(TestUser $user): array
{
    $row = DB::table($user->getTable())->where('id', $user->id)->first();
    $torrents = [];

    foreach (TorrentUser::query()->where('user_id', $user->id)->orderBy('torrent_id')->get() as $torrentUser) {
        $torrents["torrent:{$torrentUser->torrent_id}"] = [$torrentUser->uploaded, $torrentUser->seedtime, $torrentUser->leechtime];
    }

    return [
        'user' => [(int) $row->uploaded, (int) $row->seedtime, (int) $row->leechtime],
        'torrents' => $torrents,
    ];
}

test('ledger_carry exists with the totals it carries', function () {
    foreach (['user_id', 'torrent_id', 'uploaded', 'downloaded', 'seedtime', 'leechtime'] as $column) {
        expect(Schema::connection((new LedgerCarry)->getConnectionName())->hasColumn('ledger_carry', $column))
            ->toBeTrue("ledger_carry.{$column}");
    }
});

test('it lives on the same connection as announce_log', function () {
    // A real connection, not just a name: Testbench rolls the migrations back
    // after the test, and they resolve this key to pick their connection.
    config(['database.connections.ledger' => config('database.connections.testing')]);
    config(['bloodhound.announce_log.connection' => 'ledger']);

    expect((new LedgerCarry)->getConnectionName())->toBe('ledger')
        ->and((new LedgerCarry)->getConnectionName())->toBe((new AnnounceLog)->getConnectionName());
});

describe('reconcile', function () {
    test('credit held as carry is expected, not drift', function () {
        carryRow($this, $this->user, $this->torrent, 0, up: 300);
        app(LedgerAggregator::class)->run();
        carried($this->user, $this->torrent, uploaded: 700);

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    test('drift beyond the carry is still reported', function () {
        carryRow($this, $this->user, $this->torrent, 0, up: 300);
        app(LedgerAggregator::class)->run();
        carried($this->user, $this->torrent, uploaded: 700);
        DB::table($this->user->getTable())->where('id', $this->user->id)->increment('uploaded', 500);

        $report = app(LedgerAuditor::class)->reconcile();

        expect($report->userDrift)->toBe([[
            'user_id' => $this->user->id,
            'column' => 'uploaded',
            'recorded' => 1_500,
            'expected' => 1_000,
        ]]);
    });

    test('a torrent held only as carry is expected too', function () {
        carried($this->user, $this->second, uploaded: 400);

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });
});

describe('rebuild', function () {
    test('restores ledger plus carry, bytes and time', function () {
        carryRow($this, $this->user, $this->torrent, 0, up: 300);
        carryRow($this, $this->user, $this->torrent, 30);
        app(LedgerAggregator::class)->run();
        carried($this->user, $this->torrent, uploaded: 700, seedtime: 5_000, leechtime: 200);
        $before = projections($this->user);

        app(LedgerRebuilder::class)->rebuild();

        expect($before)->toBe([
            'user' => [1_000, 6_800, 200],
            'torrents' => ["torrent:{$this->torrent->id}" => [1_000, 6_800, 200]],
        ])->and(projections($this->user))->toBe($before);
    });

    // Every row for a torrent can be pruned. Its credit must survive a rebuild
    // with no rows to replay, which means a torrent_user row made from carry.
    test('restores a torrent held only as carry, even after its row is lost', function () {
        carried($this->user, $this->second, uploaded: 400, seedtime: 900);
        $before = projections($this->user);

        TorrentUser::query()->delete();
        app(LedgerRebuilder::class)->rebuild();

        expect(projections($this->user))->toBe($before);
    });

    test('a scoped rebuild counts that user\'s carry and leaves others alone', function () {
        carried($this->user, $this->torrent, uploaded: 700);
        carried($this->other, $this->torrent, uploaded: 900);
        DB::table($this->user->getTable())->update(['uploaded' => 1]);

        app(LedgerRebuilder::class)->rebuild($this->user->id);

        expect(projections($this->user)['user'][0])->toBe(700)
            ->and((int) DB::table($this->other->getTable())->where('id', $this->other->id)->value('uploaded'))->toBe(1);
    });
});
