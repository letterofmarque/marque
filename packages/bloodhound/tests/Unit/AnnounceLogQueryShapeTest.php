<?php

declare(strict_types=1);

// CP #534 asks for the query SHAPE to be checked, not just the results —
// "each method actually uses the indexes from Checkpoint 1 (no full-table
// scans on the common queries)". Correct-but-unindexed is exactly the failure
// this catches: the assertions above this file's siblings would all still pass
// against a service that scanned the whole table every time, and on an
// announce log that's the one table guaranteed to be enormous.
//
// EXPLAIN QUERY PLAN is SQLite-specific, and the package test suite is SQLite
// (see TestCase::defineEnvironment). Marque is DB-agnostic in what it SHIPS —
// no raw SQL in src/ — which this doesn't violate: it's a test-only probe of
// the planner, asserting the indexes CP #532 created are reachable by the
// queries CP #534 writes. On MySQL/Postgres the same index/where/order shapes
// are what their planners want too; this guards the shape, not the engine.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Contracts\AnnounceLogServiceInterface;
use Marque\Bloodhound\Models\AnnounceLog;

/**
 * Capture the SQL the service actually emits, then ask SQLite how it plans to
 * run it.
 */
function planFor(callable $call): string
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $call();

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect($log)->toHaveCount(1, 'expected the service method to emit exactly one query');

    $plan = DB::select('EXPLAIN QUERY PLAN '.$log[0]['query'], $log[0]['bindings']);

    return collect($plan)->pluck('detail')->implode(' | ');
}

beforeEach(function () {
    $this->service = app(AnnounceLogServiceInterface::class);

    // The planner picks an index based on the schema, not the row count, so a
    // single row is enough to make the plan meaningful.
    AnnounceLog::create([
        'user_id' => 1,
        'torrent_id' => 1,
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'event' => 'regular',
        'ip' => '10.0.0.1',
        'port' => 51413,
        'user_agent' => null,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'upload_delta' => 0,
        'download_delta' => 0,
        'anti_cheat_flagged' => true,
        'anti_cheat_reason' => 'test',
    ]);
});

it('uses the user_id index for forUser', function () {
    $plan = planFor(fn () => $this->service->forUser(1));

    expect($plan)->toContain('announce_log_user_id_created_at_index')
        ->and($plan)->not->toContain('SCAN announce_log');
});

it('uses the user_id index for forUser with a $since bound', function () {
    $plan = planFor(fn () => $this->service->forUser(1, Carbon::parse('2026-08-01')));

    expect($plan)->toContain('announce_log_user_id_created_at_index')
        ->and($plan)->not->toContain('SCAN announce_log');
});

it('uses the torrent_id index for forTorrent', function () {
    $plan = planFor(fn () => $this->service->forTorrent(1));

    expect($plan)->toContain('announce_log_torrent_id_created_at_index')
        ->and($plan)->not->toContain('SCAN announce_log');
});

it('uses one of the two available indexes for forUserAndTorrent', function () {
    // Both columns are indexed here, so which one the planner leads on is its
    // call — it picks by estimated selectivity, not by where-clause order, and
    // the answer legitimately differs between engines and as data grows. What
    // matters is that it searches an index rather than scanning; pinning the
    // specific index would be asserting a planner preference, not a property
    // of our query.
    $plan = planFor(fn () => $this->service->forUserAndTorrent(1, 1));

    expect($plan)->toMatch('/USING INDEX announce_log_(user_id|torrent_id)_created_at_index/')
        ->and($plan)->not->toContain('SCAN announce_log');
});

it('uses the flagged index for flagged', function () {
    $plan = planFor(fn () => $this->service->flagged());

    expect($plan)->toContain('announce_log_anti_cheat_flagged_index')
        ->and($plan)->not->toContain('SCAN announce_log');
});

it('uses the ip index for byIp', function () {
    $plan = planFor(fn () => $this->service->byIp('10.0.0.1'));

    expect($plan)->toContain('announce_log_ip_created_at_index')
        ->and($plan)->not->toContain('SCAN announce_log');
});

it('satisfies the ordering from the index rather than sorting', function () {
    // The composite indexes carry created_at as their second column, so a
    // newest-first read should come straight off the index. A "USE TEMP B-TREE
    // FOR ORDER BY" here means the ordering silently stopped matching the
    // index — the exact regression ordering by id instead would introduce.
    expect(planFor(fn () => $this->service->forUser(1)))
        ->not->toContain('USE TEMP B-TREE');
});
