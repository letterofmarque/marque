<?php

declare(strict_types=1);

// Job #193 (design 5.1): the aggregator's cursor is the control that stops a
// batch being folded twice (F11), a row still being written being skipped
// (F12), and a rebuild being overwritten by a fold landing mid-rebuild (F13).

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::create(['name' => 'C', 'email' => 'cursor@example.com', 'password' => 'p']);
});

function cursorRow(TestUser $user, int $up): AnnounceLog
{
    return AnnounceLog::create([
        'user_id' => $user->id, 'torrent_id' => 1, 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'event' => 'regular',
        'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => $up, 'downloaded' => 0, 'left' => 0,
        'upload_delta' => $up, 'download_delta' => 0, 'seed_seconds' => 60,
    ]);
}

describe('the cursor advance is a compare-and-swap (F11)', function () {
    // Something moved the cursor while this batch was being folded: another
    // fold, by whatever route got past the lock. Folding on would credit the
    // batch twice; the advance must refuse, and the whole batch roll back.
    test('a batch whose cursor moved underneath it rolls back instead of double-counting', function () {
        cursorRow($this->user, 1_000);

        $racing = new class extends LedgerAggregator
        {
            protected function applyDeltas($rows): void
            {
                parent::applyDeltas($rows);

                DB::table('ledger_cursors')->where('stream', self::STREAM)->update(['position' => $rows->last()->id]);
            }
        };

        expect(fn () => $racing->run())->toThrow(RuntimeException::class);

        expect($this->user->fresh()->uploaded)->toBe(0);
    });

    test('a fold locks the cursor row before it reads where to start', function () {
        cursorRow($this->user, 1_000);

        DB::enableQueryLog();
        app(LedgerAggregator::class)->run();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $lock = $queries->search(fn ($sql) => str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'ledger_cursors'));
        $read = $queries->search(fn ($sql) => str_contains($sql, 'from') && str_contains($sql, 'announce_log'));

        expect($lock)->not->toBeFalse()
            ->and($lock)->toBeLessThan($read);
    });
});

describe('the scheduled fold stops at the horizon (F12)', function () {
    // A row whose insert was still in flight when a later id committed would
    // sit below a cursor that had moved past it, never folded. The scheduled
    // fold only goes as far as the highest id the previous run saw, so every
    // row it folds has had a whole tick to commit.
    test('folds only up to the highest id the previous run saw', function () {
        cursorRow($this->user, 1_000);

        expect(app(LedgerAggregator::class)->runScheduled())->toBe(0)
            ->and($this->user->fresh()->uploaded)->toBe(0);

        cursorRow($this->user, 500);

        expect(app(LedgerAggregator::class)->runScheduled())->toBe(1)
            ->and($this->user->fresh()->uploaded)->toBe(1_000);

        expect(app(LedgerAggregator::class)->runScheduled())->toBe(1)
            ->and($this->user->fresh()->uploaded)->toBe(1_500);
    });

    test('the scheduled command folds to the horizon, two ticks to drain a backlog', function () {
        cursorRow($this->user, 1_000);
        cursorRow($this->user, 2_000);

        $this->artisan('bloodhound:aggregate-ledger')->assertSuccessful();
        expect($this->user->fresh()->uploaded)->toBe(0);

        $this->artisan('bloodhound:aggregate-ledger')->assertSuccessful();
        expect($this->user->fresh()->uploaded)->toBe(3_000);
    });

    test('run() folds to the head, for migrations and catching up by hand', function () {
        cursorRow($this->user, 1_000);

        expect(app(LedgerAggregator::class)->run())->toBe(1)
            ->and($this->user->fresh()->uploaded)->toBe(1_000);
    });

    test('the horizon never moves backwards', function () {
        cursorRow($this->user, 1_000);
        app(LedgerAggregator::class)->runScheduled();
        $horizon = LedgerCursor::query()->where('stream', LedgerAggregator::STREAM)->value('horizon');

        AnnounceLog::query()->delete();
        app(LedgerAggregator::class)->runScheduled();

        expect((int) LedgerCursor::query()->where('stream', LedgerAggregator::STREAM)->value('horizon'))->toBe((int) $horizon);
    });
});

describe('a rebuild takes the same lock (F13)', function () {
    test('a rebuild locks the cursor row before it resets anything', function () {
        cursorRow($this->user, 1_000);
        app(LedgerAggregator::class)->run();

        DB::enableQueryLog();
        app(LedgerRebuilder::class)->rebuild();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $lock = $queries->search(fn ($sql) => str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'ledger_cursors'));
        $reset = $queries->search(fn ($sql) => str_starts_with(strtolower($sql), 'update') && ! str_contains($sql, 'ledger_cursors'));

        expect($lock)->not->toBeFalse()
            ->and($lock)->toBeLessThan($reset);
    });
});
