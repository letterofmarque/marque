<?php

declare(strict_types=1);

// Spec #98's retention mechanism. The default (retention_days null) keeps
// everything forever — deliberate, per the Spec: once an operator opts into
// logging at all, retention is their call, not a paternalistic default they'd
// have to discover and override. So "does nothing when null" is the behaviour
// under test, not an edge case.

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Services\LedgerAggregator;

function seedLogRow(int $daysAgo, array $overrides = []): AnnounceLog
{
    $row = AnnounceLog::create(array_merge([
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
        'anti_cheat_flagged' => false,
        'anti_cheat_reason' => null,
    ], $overrides));

    // created_at is DB-defaulted (useCurrent) on an append-only table, so
    // ageing a row has to be a direct update.
    AnnounceLog::whereKey($row->id)->update([
        'created_at' => Carbon::now()->subDays($daysAgo),
    ]);

    return $row->refresh();
}

describe('retention_days is null (the default)', function () {
    it('deletes nothing regardless of age', function () {
        config(['bloodhound.announce_log.retention_days' => null]);

        seedLogRow(1);
        seedLogRow(400);
        seedLogRow(5000);

        $this->artisan('bloodhound:prune-announce-log')->assertSuccessful();

        expect(AnnounceLog::count())->toBe(3);
    });

    it('says so rather than silently doing nothing', function () {
        config(['bloodhound.announce_log.retention_days' => null]);

        $this->artisan('bloodhound:prune-announce-log')
            ->expectsOutputToContain('retention')
            ->assertSuccessful();
    });
});

describe('retention_days set', function () {
    // These predate the reconciliation floor added in CP7. Pruning now also
    // requires a row to have been aggregated, so each marks the ledger as
    // consumed AFTER seeding — otherwise it asserts the floor's behaviour
    // rather than the retention window's, which is covered separately below.

    it('deletes only rows older than the cutoff', function () {
        config(['bloodhound.announce_log.retention_days' => 30]);

        $keep = seedLogRow(1);
        $keepEdge = seedLogRow(29);
        $dropEdge = seedLogRow(31);
        $drop = seedLogRow(365);

        markLedgerFullyAggregated();

        $this->artisan('bloodhound:prune-announce-log')->assertSuccessful();

        expect(AnnounceLog::count())->toBe(2)
            ->and(AnnounceLog::find($keep->id))->not->toBeNull()
            ->and(AnnounceLog::find($keepEdge->id))->not->toBeNull()
            ->and(AnnounceLog::find($dropEdge->id))->toBeNull()
            ->and(AnnounceLog::find($drop->id))->toBeNull();
    });

    it('reports how many rows it deleted', function () {
        config(['bloodhound.announce_log.retention_days' => 30]);

        seedLogRow(90);
        seedLogRow(90);
        seedLogRow(1);

        markLedgerFullyAggregated();

        $this->artisan('bloodhound:prune-announce-log')
            ->expectsOutputToContain('2')
            ->assertSuccessful();
    });

    it('succeeds when there is nothing to prune', function () {
        config(['bloodhound.announce_log.retention_days' => 30]);

        seedLogRow(1);

        $this->artisan('bloodhound:prune-announce-log')->assertSuccessful();

        expect(AnnounceLog::count())->toBe(1);
    });

    it('handles an empty table', function () {
        config(['bloodhound.announce_log.retention_days' => 30]);

        $this->artisan('bloodhound:prune-announce-log')->assertSuccessful();

        expect(AnnounceLog::count())->toBe(0);
    });

    it('accepts retention_days as a string, as env() would supply it', function () {
        // BLOODHOUND_ANNOUNCE_LOG_RETENTION_DAYS comes out of the environment
        // as a string; a naive `$days > 0` check passes but subDays('30')
        // behaviour shouldn't be left to chance.
        config(['bloodhound.announce_log.retention_days' => '30']);

        seedLogRow(90);
        seedLogRow(1);

        markLedgerFullyAggregated();

        $this->artisan('bloodhound:prune-announce-log')->assertSuccessful();

        expect(AnnounceLog::count())->toBe(1);
    });
});

describe('scheduling', function () {
    it('is registered on the scheduler', function () {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn ($c) => str_contains($c, 'bloodhound:prune-announce-log'));

        expect($events)->not->toBeEmpty();
    });
});

// CP7 (Spec #99). The floor: pruning below the reconciliation watermark would
// destroy the rows a rebuild replays, so a "cleanup" job could silently make
// every total unverifiable — the exact opposite of what the ledger is for.
/**
 * Mark every existing ledger row as consumed by the aggregator.
 */
function markLedgerFullyAggregated(): void
{
    $highest = (int) AnnounceLog::max('id');

    LedgerCursor::updateOrCreate(
        ['stream' => LedgerAggregator::STREAM],
        ['position' => $highest],
    );
}

describe('the reconciliation floor', function () {
    it('refuses to delete rows the aggregator has not yet consumed', function () {
        config(['bloodhound.announce_log.retention_days' => 1]);

        $old = seedLogRow(daysAgo: 30);

        // Nothing aggregated: the watermark is 0, so this row is still needed.
        LedgerCursor::query()->delete();

        test()->artisan('bloodhound:prune-announce-log')->assertSuccessful();

        expect(AnnounceLog::find($old->id))->not->toBeNull();
    });

    it('deletes aged rows once they are safely below the watermark', function () {
        config(['bloodhound.announce_log.retention_days' => 1]);

        $old = seedLogRow(daysAgo: 30);

        LedgerCursor::create([
            'stream' => LedgerAggregator::STREAM,
            'position' => $old->id,
        ]);

        test()->artisan('bloodhound:prune-announce-log')->assertSuccessful();

        expect(AnnounceLog::find($old->id))->toBeNull();
    });

    it('says why it kept rows it would otherwise have pruned', function () {
        config(['bloodhound.announce_log.retention_days' => 1]);

        seedLogRow(daysAgo: 30);
        LedgerCursor::query()->delete();

        test()->artisan('bloodhound:prune-announce-log')
            ->expectsOutputToContain('not yet aggregated')
            ->assertSuccessful();
    });
});
