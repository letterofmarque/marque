<?php

declare(strict_types=1);

// #11070 item 10. Totals are folded from the ledger by bloodhound:aggregate-
// ledger, on the scheduler. twentyt's production box had no schedule:run at
// all, so nothing would ever have been credited, and nothing said so. A check
// on the schedule can't catch a schedule that never runs, so announces ask:
// at most once every five minutes, is the oldest unfolded row too old?

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerFreshness;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    Cache::flush();
    $this->user = makeTrackerUser('freshfreshfreshfreshfreshfresh00', 'fresh@example.com');
    $this->torrent = Torrent::create(['name' => 'F', 'info_hash' => str_repeat('f', 40), 'size' => 1_000, 'user_id' => $this->user->id]);
});

function freshness(): LedgerFreshness
{
    return app(LedgerFreshness::class);
}

it('is not stalled with an empty ledger', function () {
    expect(freshness()->stalledSince())->toBeNull();
});

it('is not stalled while unfolded rows are recent', function () {
    announceAt($this->user, $this->torrent, now()->subMinutes(2), left: 0, event: 'started');
    $this->travelBack();

    expect(freshness()->stalledSince())->toBeNull();
});

it('is stalled when the oldest unfolded row is older than ten minutes', function () {
    announceAt($this->user, $this->torrent, now()->subMinutes(30), left: 0, event: 'started');
    $this->travelBack();

    expect(freshness()->stalledSince()?->toDateTimeString())
        ->toBe(AnnounceLog::query()->min('created_at'));
});

it('is not stalled once the rows are folded', function () {
    announceAt($this->user, $this->torrent, now()->subMinutes(30), left: 0, event: 'started');
    $this->travelBack();

    app(LedgerAggregator::class)->run();

    expect(freshness()->stalledSince())->toBeNull();
});

it('reports a stalled ledger from an announce, naming the scheduler, once in five minutes', function () {
    announceAt($this->user, $this->torrent, now()->subMinutes(30), left: 0, event: 'started');
    $this->travelBack();
    Log::spy();

    announceAt($this->user, $this->torrent, now(), left: 0);
    announceAt($this->user, $this->torrent, now()->addMinute(), left: 0);

    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => str_contains($message, 'bloodhound:aggregate-ledger')
        && str_contains($message, 'schedule:run'));
});

it('says nothing from an announce while the ledger is fresh', function () {
    Log::spy();

    announceAt($this->user, $this->torrent, now(), left: 0, event: 'started');

    Log::shouldNotHaveReceived('error');
});

it('never fails an announce, whatever goes wrong while checking', function () {
    app()->instance(LedgerFreshness::class, new class extends LedgerFreshness
    {
        public function stalledSince(): ?Carbon
        {
            throw new RuntimeException('database gone');
        }
    });

    announceAt($this->user, $this->torrent, now(), left: 0, event: 'started');

    expect(AnnounceLog::query()->count())->toBe(1);
});
