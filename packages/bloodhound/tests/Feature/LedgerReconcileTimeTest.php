<?php

declare(strict_types=1);

// Job #112 CP5 (Spec #121): reconciliation checks seedtime and leechtime as it
// checks bytes.
//
// Seedtime was a column nothing computed, so nothing could say when it was
// wrong. Now each ledger row carries the seconds it credited (Job #193), and
// a stored figure is checked against their sum up to the watermark, plus
// whatever pruning carried forward.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));

    $this->user = TestUser::create(['name' => 'A', 'email' => 'rt@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create([
        'name' => 'One', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
});

/**
 * A row crediting $seed or $leech seconds, as the announce path writes them.
 */
function reconcileTimeRow($test, int $minutesAgo, int $left = 0, int $seed = 0, int $leech = 0): void
{
    $row = AnnounceLog::create([
        'user_id' => $test->user->id, 'torrent_id' => $test->torrent->id, 'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'event' => 'regular', 'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => 0, 'downloaded' => 0,
        'left' => $left, 'upload_delta' => 0, 'download_delta' => 0,
        'seed_seconds' => $seed, 'leech_seconds' => $leech,
    ]);

    $row->forceFill(['created_at' => Carbon::now()->subMinutes($minutesAgo)])->save();
}

test('aggregated time reconciles clean', function () {
    reconcileTimeRow($this, 90, left: 100);
    reconcileTimeRow($this, 60, leech: 1_800);
    reconcileTimeRow($this, 30, seed: 1_800);
    app(LedgerAggregator::class)->run();

    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
});

test('a user\'s seedtime that drifted is reported, with what the ledger says', function () {
    reconcileTimeRow($this, 60);
    reconcileTimeRow($this, 30, seed: 1_800);
    app(LedgerAggregator::class)->run();
    DB::table($this->user->getTable())->where('id', $this->user->id)->update(['seedtime' => 99]);

    expect(app(LedgerAuditor::class)->reconcile()->userDrift)->toBe([[
        'user_id' => $this->user->id,
        'column' => 'seedtime',
        'recorded' => 99,
        'expected' => 1800,
    ]]);
});

test('a per-torrent leechtime that drifted is reported', function () {
    reconcileTimeRow($this, 60, left: 100);
    reconcileTimeRow($this, 30, left: 50, leech: 1_800);
    app(LedgerAggregator::class)->run();
    TorrentUser::query()->update(['leechtime' => 7]);

    expect(app(LedgerAuditor::class)->reconcile()->torrentUserDrift)->toBe([[
        'user_id' => $this->user->id,
        'torrent_id' => $this->torrent->id,
        'column' => 'leechtime',
        'recorded' => 7,
        'expected' => 1800,
    ]]);
});

// The interval a not-yet-aggregated row closes has not been credited yet, so
// it is backlog, not drift: the same rule as bytes.
test('time the aggregator has not folded yet is backlog, not drift', function () {
    reconcileTimeRow($this, 60);
    app(LedgerAggregator::class)->run();
    reconcileTimeRow($this, 30, seed: 1_800);

    $report = app(LedgerAuditor::class)->reconcile();

    expect($report->hasDrift())->toBeFalse()
        ->and($report->pending)->toBe(1);
});

test('time carried forward by a prune reconciles clean', function () {
    config(['bloodhound.announce_log.retention_days' => 1]);
    reconcileTimeRow($this, 60 * 24 * 3);
    reconcileTimeRow($this, 60 * 24 * 3 - 30, seed: 1_800);
    reconcileTimeRow($this, 30, seed: 3_600);
    app(LedgerAggregator::class)->run();

    $this->artisan('bloodhound:prune-announce-log')->assertSuccessful();

    expect(AnnounceLog::query()->count())->toBe(1)
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
});

// Totals that lost time the ledger records (a bad write, a hand edit) read as
// drift, and the report's fix, rebuild-totals, is safe and clears it.
test('time missing from the totals reads as drift until a rebuild', function () {
    reconcileTimeRow($this, 60);
    reconcileTimeRow($this, 30, seed: 1_800);
    app(LedgerAggregator::class)->run();
    DB::table($this->user->getTable())->update(['seedtime' => 0]);
    TorrentUser::query()->update(['seedtime' => 0]);

    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeTrue();

    app(LedgerRebuilder::class)->rebuild();

    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
});
