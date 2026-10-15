<?php

declare(strict_types=1);

// Job #112 CP4 (Spec #121): the decisions that make this design differ from
// the field's, each asserted directly — through the tracker, then the
// aggregator, never by writing ledger rows or totals by hand.
//
// Each test pins figures that a researched alternative would get wrong:
//   - Unit3D discards the interval in which a peer completes
//   - U-232 credits a whole overnight gap; Unit3D credits it zero
//   - nobody uses `stopped` to end a session

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => false]);

    $this->user = makeTrackerUser('bbbbccccddddeeeeffffgggghhhhiiii', 'behaviour@example.com');
    $this->torrent = Torrent::create([
        'name' => 'Behaviour Torrent',
        'info_hash' => str_repeat('e', 40),
        'size' => 1_000,
        'user_id' => $this->user->id,
    ]);

    $this->t = Carbon::parse('2026-10-01 10:00:00');
});

// Criterion 1: the plain case, reached through real announces.
test('a user who has seeded has seedtime on both the users table and torrent_user', function () {
    announceAt($this->user, $this->torrent, $this->t, 0, 'started');
    announceAt($this->user, $this->torrent, $this->t->copy()->addMinutes(30), 0);
    announceAt($this->user, $this->torrent, $this->t->copy()->addMinutes(60), 0);

    app(LedgerAggregator::class)->run();

    expect(timeTotals($this->user, $this->torrent))->toBe([
        'user' => [3600, 0],
        'torrent' => [3600, 0],
    ]);
});

// Criterion 3. The completing interval is leech time, all of it.
test('the interval in which a peer completes is credited entirely as leech time', function () {
    announceAt($this->user, $this->torrent, $this->t, 1_000, 'started');
    announceAt($this->user, $this->torrent, $this->t->copy()->addMinutes(30), 0, 'completed');

    app(LedgerAggregator::class)->run();

    // Unit3D would credit neither: its guard needs both ends seeding.
    expect(timeTotals($this->user, $this->torrent))->toBe([
        'user' => [0, 1800],
        'torrent' => [0, 1800],
    ]);
});

// Criterion 4, both directions: a test asserting only the long gap would
// pass against a constant 3600.
test('a gap past peer_expiry credits the cap, and a normal gap credits itself', function () {
    announceAt($this->user, $this->torrent, $this->t, 0, 'started');
    announceAt($this->user, $this->torrent, $this->t->copy()->addHours(6), 0);
    announceAt($this->user, $this->torrent, $this->t->copy()->addHours(6)->addMinutes(30), 0);

    app(LedgerAggregator::class)->run();

    // 6h credits 3600 (not U-232's 21600, not Unit3D's 0); then 1800.
    expect(timeTotals($this->user, $this->torrent)['user'])->toBe([3600 + 1800, 0]);
});

// Criterion 5. The peer said it was leaving; the gap is not seeding.
test('a peer that stops at 10:00 and returns at 16:00 is credited nothing for the gap', function () {
    announceAt($this->user, $this->torrent, $this->t, 0, 'started');
    announceAt($this->user, $this->torrent, $this->t->copy()->addMinutes(30), 0, 'stopped');
    announceAt($this->user, $this->torrent, $this->t->copy()->addHours(6), 0, 'started');
    announceAt($this->user, $this->torrent, $this->t->copy()->addHours(6)->addMinutes(30), 0);

    app(LedgerAggregator::class)->run();

    // 1800 up to the stop, 1800 after the return; the six hours between, zero
    // (a capped gap would have added 3600).
    expect(timeTotals($this->user, $this->torrent)['user'])->toBe([3600, 0]);
});
