<?php

declare(strict_types=1);

// Job #256 step 1. Hit-and-run measures "not seeding" from when a member was
// last seen on a torrent. updated_at won't do: a rebuild moves it. So the
// aggregator keeps torrent_user.last_seen (unix seconds) as the newest folded
// row's stamp, never moving it backwards, and nothing else touches it.

use Illuminate\Support\Carbon;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->member = TestUser::create(['name' => 'Seen', 'email' => 'seen@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create(['name' => 'Seen', 'info_hash' => str_repeat('5', 40), 'size' => 1_000, 'user_id' => $this->member->id]);
});

function seenRow(object $test, string $at, int $seedSeconds = 60): void
{
    $test->travelTo($at);

    AnnounceLog::create([
        'user_id' => $test->member->id,
        'torrent_id' => $test->torrent->id,
        'peer_id' => '-qB4210-ssssssssssss',
        'event' => 'regular',
        'ip' => '10.0.0.5',
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'upload_delta' => 0,
        'download_delta' => 0,
        'seed_seconds' => $seedSeconds,
        'leech_seconds' => 0,
        'prior_up' => 0,
        'prior_down' => 0,
    ]);
}

function lastSeen(object $test): ?int
{
    $value = TorrentUser::query()->where('user_id', $test->member->id)->where('torrent_id', $test->torrent->id)->value('last_seen');

    return $value === null ? null : (int) $value;
}

it('is the newest folded row\'s time, on a new row', function () {
    seenRow($this, '2026-11-01 10:00:00');
    seenRow($this, '2026-11-01 10:30:00');

    app(LedgerAggregator::class)->run();

    expect(lastSeen($this))->toBe(Carbon::parse('2026-11-01 10:30:00')->getTimestamp());
});

it('moves forward on an existing row', function () {
    seenRow($this, '2026-11-01 10:00:00');
    app(LedgerAggregator::class)->run();

    seenRow($this, '2026-11-02 08:00:00');
    app(LedgerAggregator::class)->run();

    expect(lastSeen($this))->toBe(Carbon::parse('2026-11-02 08:00:00')->getTimestamp());
});

it('never moves backwards when an older-stamped row folds later', function () {
    seenRow($this, '2026-11-02 08:00:00');
    app(LedgerAggregator::class)->run();

    // A row whose insert was still committing: an older stamp, a later fold.
    seenRow($this, '2026-11-01 09:00:00');
    app(LedgerAggregator::class)->run();

    expect(lastSeen($this))->toBe(Carbon::parse('2026-11-02 08:00:00')->getTimestamp());
});

it('is left alone by a rebuild', function () {
    seenRow($this, '2026-11-01 10:00:00');
    app(LedgerAggregator::class)->run();

    $this->travelTo('2026-12-01 00:00:00');
    app(LedgerRebuilder::class)->rebuild();

    expect(lastSeen($this))->toBe(Carbon::parse('2026-11-01 10:00:00')->getTimestamp());
});

it('is not set by credit that is not a ledger row (carry)', function () {
    app(LedgerAggregator::class)->addToTotals(["{$this->member->id}:{$this->torrent->id}" => [
        'user_id' => $this->member->id, 'torrent_id' => $this->torrent->id,
        'uploaded' => 5, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0,
    ]]);

    expect(lastSeen($this))->toBeNull();
});

// TouchUp #58: a row the rebuild has to re-create was stamped now(), so to
// hit-and-run (which counts rows created since the rule began) old activity
// looked new, and the away clock restarted from today.
it('stamps a row the rebuild re-creates from the ledger, not from today', function () {
    seenRow($this, '2026-11-01 10:00:00');
    seenRow($this, '2026-11-03 12:00:00');
    app(LedgerAggregator::class)->run();
    TorrentUser::query()->where('user_id', $this->member->id)->delete();

    $this->travelTo('2026-12-01 00:00:00');
    app(LedgerRebuilder::class)->rebuild();

    $row = TorrentUser::query()->where('user_id', $this->member->id)->where('torrent_id', $this->torrent->id)->first();

    expect($row->created_at->getTimestamp())->toBe(Carbon::parse('2026-11-01 10:00:00')->getTimestamp())
        ->and(lastSeen($this))->toBe(Carbon::parse('2026-11-03 12:00:00')->getTimestamp());
});

it('stamps a pair known only from carried history with the carry, the oldest stamp left', function () {
    $this->travelTo('2026-10-01 00:00:00');
    LedgerCarry::query()->create([
        'user_id' => $this->member->id, 'torrent_id' => $this->torrent->id,
        'uploaded' => 0, 'downloaded' => 0, 'seedtime' => 600, 'leechtime' => 0,
    ]);

    $this->travelTo('2026-12-01 00:00:00');
    app(LedgerRebuilder::class)->rebuild();

    $row = TorrentUser::query()->where('user_id', $this->member->id)->where('torrent_id', $this->torrent->id)->first();

    expect($row->created_at->getTimestamp())->toBe(Carbon::parse('2026-10-01 00:00:00')->getTimestamp())
        ->and(lastSeen($this))->toBe(Carbon::parse('2026-10-01 00:00:00')->getTimestamp());
});
