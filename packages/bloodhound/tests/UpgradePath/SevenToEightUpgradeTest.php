<?php

declare(strict_types=1);

// Job #193: a bloodhound 7.x install migrating to 8.0, end to end on the real
// engines: imported seedtime survives (F10), the backfill credits 7.x history
// its time as carry, and reconcile is clean afterwards.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;

test('a 7.x install upgrades, backfills, and reconciles clean', function () {
    // Back to 7.0.0's schema: everything from leechtime (unreleased) on.
    $from = '2026_10_13_000001_add_leechtime_columns';
    $steps = DB::table('migrations')->where('migration', '>=', $from)->count();
    $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

    $table = (new TestUser)->getTable();
    DB::table($table)->insert(['id' => 51, 'name' => 'Seven', 'email' => 'seven@example.com', 'password' => 'p', 'uploaded' => 1_000, 'seedtime' => 7_200]);
    DB::table('torrent_user')->insert(['user_id' => 51, 'torrent_id' => 3, 'uploaded' => 1_000, 'downloaded' => 0, 'seedtime' => 0, 'times_completed' => 0, 'created_at' => now(), 'updated_at' => now()]);

    // 7.x rows: a session with a stop, which 7.x credited no time and no stop bytes.
    $t = Carbon::parse('2026-10-01 10:00:00');
    foreach ([[0, 'started', 0, 0, null], [30, 'regular', 1_000, 1_000, 0], [40, 'stopped', 1_600, 0, null]] as [$m, $event, $up, $delta, $prior]) {
        DB::table('announce_log')->insert([
            'user_id' => 51, 'torrent_id' => 3, 'peer_id' => '-qB4210-777777777777', 'event' => $event,
            'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => $up, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => $delta, 'download_delta' => 0, 'prior_up' => $prior, 'prior_down' => $prior === null ? null : 0,
            'anti_cheat_flagged' => false, 'created_at' => $t->copy()->addMinutes($m),
        ]);
    }
    DB::table('ledger_cursors')->updateOrInsert(['stream' => LedgerAggregator::STREAM], ['position' => (int) DB::table('announce_log')->max('id'), 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    // The imported 7,200 s is carry now, at user level (no torrent held it).
    expect((int) LedgerCarry::query()->where('user_id', 51)->where('torrent_id', 0)->value('seedtime'))->toBe(7_200)
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    $this->artisan('bloodhound:backfill-ledger')->assertSuccessful();

    $user = DB::table($table)->where('id', 51)->first();

    // 7,200 imported + 1,800 (0→30) + 600 (30→40, the stop); 600 stopped bytes.
    expect([(int) $user->seedtime, (int) $user->uploaded])->toBe([9_600, 1_600])
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    app(LedgerRebuilder::class)->rebuild();

    $user = DB::table($table)->where('id', 51)->first();

    expect([(int) $user->seedtime, (int) $user->uploaded])->toBe([9_600, 1_600])
        ->and(app(LedgerAuditor::class)->audit()->hasBreaks())->toBeFalse();
});
