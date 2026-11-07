<?php

declare(strict_types=1);

// Job #213: ledger_adjustments, an operator's correction for totals the ledger
// can't explain.
//
// Some totals are real but no ledger row accounts for them: a 4.1.0 install's
// unexplained bytes (#11051), figures imported from an earlier tracker, a speed
// forfeit an operator decides was wrong. Reconcile reads those as drift, and
// the rebuild it recommends erases them. An adjustment is an operator saying,
// on the record, how much and why: the ledger is its rows, its carry and its
// adjustments.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\LedgerAdjustment;

function adjustment(array $overrides = []): LedgerAdjustment
{
    return LedgerAdjustment::query()->create(array_merge([
        'user_id' => 7,
        'torrent_id' => 3,
        'uploaded' => 1_000,
        'downloaded' => 0,
        'seedtime' => 0,
        'leechtime' => 0,
        'reason' => 'Imported from the old tracker',
        'made_by' => 1,
    ], $overrides));
}

describe('the ledger_adjustments table', function () {
    it('lives on announce_log\'s connection, beside the carry', function () {
        $connection = config('bloodhound.announce_log.connection');

        expect(Schema::connection($connection)->hasTable('ledger_adjustments'))->toBeTrue()
            ->and((new LedgerAdjustment)->getConnectionName())->toBe($connection);
    });

    // Signed: an adjustment can take credit away as well as give it, and a
    // wrong one is answered by another signed the other way.
    it('keeps signed amounts', function () {
        $row = adjustment(['uploaded' => -5_000_000_000, 'downloaded' => 7, 'seedtime' => -60, 'leechtime' => 3_600]);

        expect($row->fresh()->only(['uploaded', 'downloaded', 'seedtime', 'leechtime']))
            ->toBe(['uploaded' => -5_000_000_000, 'downloaded' => 7, 'seedtime' => -60, 'leechtime' => 3_600]);
    });

    it('keeps who made it, why, and when', function () {
        $row = adjustment()->fresh();

        expect($row->made_by)->toBe(1)
            ->and($row->reason)->toBe('Imported from the old tracker')
            ->and($row->created_at)->not->toBeNull();
    });

    // A correction nobody can account for is the silent change this exists to
    // prevent, so the database refuses one as well as the service.
    //
    // In a savepoint: PostgreSQL aborts the whole transaction a test runs in
    // after any error, which would fail the test's own teardown.
    it('refuses a row without a reason or an author', function (string $column) {
        (new LedgerAdjustment)->getConnection()->transaction(fn () => LedgerAdjustment::query()->insert([
            'user_id' => 7, 'torrent_id' => 3, 'uploaded' => 1,
            'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0,
            'reason' => 'r', 'made_by' => 1, 'created_at' => now(),
            $column => null,
        ]));
    })->with(['reason', 'made_by'])->throws(QueryException::class);
});

describe('an adjustment, once made', function () {
    it('cannot be edited', function () {
        $row = adjustment();
        $row->uploaded = 1;
        $row->save();
    })->throws(LogicException::class, 'append-only');

    it('cannot be deleted', function () {
        adjustment()->delete();
    })->throws(LogicException::class, 'append-only');

    it('is still there, unchanged, after both were refused', function () {
        $row = adjustment();

        try {
            $row->forceFill(['uploaded' => 1])->save();
        } catch (LogicException) {
        }

        try {
            $row->delete();
        } catch (LogicException) {
        }

        expect(LedgerAdjustment::query()->sole()->uploaded)->toBe(1_000);
    });
});
