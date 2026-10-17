<?php

declare(strict_types=1);

// Job #193: an install from before the ledger (pre-5.0) migrating straight to
// 8.0. The 5.0 opening-balance migration zeroes each member's totals into a
// balance row and folds them back. Its fold is today's aggregator, which needs
// columns later migrations add; run before they exist, it failed, and the
// upgrade stopped half-done with every member at zero.

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Tests\TestUser;

test('a pre-ledger install migrates straight to 8.0 with its members\' totals intact', function () {
    $from = '2026_09_03_000004_record_opening_balances';
    $steps = DB::table('migrations')->where('migration', '>=', $from)->count();

    $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

    expect(DB::table('migrations')->where('migration', $from)->exists())->toBeFalse();

    // A member as a pre-ledger install held them: totals on the user, no rows.
    $table = (new TestUser)->getTable();
    DB::table($table)->insert(['id' => 41, 'name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => 'p', 'uploaded' => 5_000, 'downloaded' => 1_000]);

    $this->artisan('migrate')->assertSuccessful();

    $user = DB::table($table)->where('id', 41)->first();

    expect([(int) $user->uploaded, (int) $user->downloaded])->toBe([5_000, 1_000])
        ->and(AnnounceLog::query()->where('event', AnnounceLog::EVENT_OPENING_BALANCE)->count())->toBe(1)
        ->and(LedgerCursor::positionFor(LedgerAggregator::STREAM))->toBe((int) AnnounceLog::query()->max('id'))
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
});
