<?php

declare(strict_types=1);

// CP8 of Build #92 (Spec #99 — the announce ledger).
//
// An install upgrading into the ledger has users.uploaded totals with nothing
// behind them. Left alone, the first reconciliation run reports every user's
// entire history as drift — for every user, forever. The alerting would be
// pure noise from day one, which is how a detection mechanism gets ignored
// and the whole Spec becomes decorative.
//
// So the migration writes one synthetic ledger row per user carrying their
// pre-ledger totals. It asserts the old number was correct as of migration.
// It is NOT evidence that it was — nothing can be, the data was never kept —
// and the docs say so plainly.
//
// The property that matters most here: a rebuild must work FROM the opening
// balance, not from zero. A rebuild that silently zeroed a user's ratio would
// be worse than the drift it is fixing.

use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Services\OpeningBalance;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->torrentOwner = TestUser::create([
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => 'password',
        'announce_key' => str_repeat('z', 32),
    ]);

    $this->torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => str_repeat('a', 40),
        'size' => 5_000_000_000,
        'user_id' => $this->torrentOwner->id,
    ]);
});

/**
 * A user carrying pre-ledger totals, as an upgrading install would have.
 */
function legacyUser(string $email, int $uploaded, int $downloaded): TestUser
{
    $user = TestUser::create([
        'name' => 'Legacy',
        'email' => $email,
        'password' => 'password',
        'announce_key' => substr(md5($email), 0, 32),
    ]);

    $user->forceFill(['uploaded' => $uploaded, 'downloaded' => $downloaded])->save();

    return $user->fresh();
}

describe('carrying pre-ledger totals in', function () {
    test('writes one opening balance row per user with a total', function () {
        legacyUser('a@example.com', 5_000, 2_000);
        legacyUser('b@example.com', 1_000, 500);

        app(OpeningBalance::class)->record();

        $rows = AnnounceLog::where('event', AnnounceLog::EVENT_OPENING_BALANCE)->get();

        expect($rows)->toHaveCount(2);
    });

    test('the row carries the user total as its delta', function () {
        $user = legacyUser('a@example.com', 5_000, 2_000);

        app(OpeningBalance::class)->record();

        $row = AnnounceLog::where('user_id', $user->id)->first();

        expect($row->upload_delta)->toBe(5_000)
            ->and($row->download_delta)->toBe(2_000);
    });

    // It is not an announce and must never be mistaken for one.
    test('is marked as an opening balance, not a real announce', function () {
        legacyUser('a@example.com', 5_000, 2_000);

        app(OpeningBalance::class)->record();

        expect(AnnounceLog::first()->event)->toBe('opening_balance');
    });

    // No peer reported this, so there is no baseline it was diffed against.
    // Null is the honest answer; a zero would claim a measurement nobody made.
    test('has no baseline, because nothing was diffed to produce it', function () {
        legacyUser('a@example.com', 5_000, 2_000);

        app(OpeningBalance::class)->record();

        $row = AnnounceLog::first();

        expect($row->prior_up)->toBeNull()
            ->and($row->prior_down)->toBeNull();
    });

    test('skips users with nothing to carry', function () {
        legacyUser('a@example.com', 0, 0);
        legacyUser('b@example.com', 5_000, 0);

        app(OpeningBalance::class)->record();

        expect(AnnounceLog::count())->toBe(1);
    });

    // Running it twice must not double anyone's ratio.
    test('is idempotent', function () {
        legacyUser('a@example.com', 5_000, 2_000);

        app(OpeningBalance::class)->record();
        app(OpeningBalance::class)->record();
        app(OpeningBalance::class)->record();

        expect(AnnounceLog::where('event', AnnounceLog::EVENT_OPENING_BALANCE)->count())->toBe(1);
    });
});

describe('reconciliation after migrating', function () {
    // The whole point. Without the opening balance this reports every user's
    // entire history as drift on the first run.
    test('reports no drift for a freshly migrated install', function () {
        legacyUser('a@example.com', 5_000, 2_000);
        legacyUser('b@example.com', 1_000, 500);

        app(OpeningBalance::class)->record();
        app(LedgerAggregator::class)->run();

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    test('real announces after migration accumulate on top', function () {
        $user = legacyUser('a@example.com', 5_000, 0);

        app(OpeningBalance::class)->record();
        app(LedgerAggregator::class)->run();

        AnnounceLog::create([
            'user_id' => $user->id,
            'torrent_id' => $this->torrent->id,
            'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'regular',
            'ip' => '10.0.0.1', 'port' => 51413,
            'uploaded' => 3_000, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => 3_000, 'download_delta' => 0,
            'prior_up' => 0, 'prior_down' => 0,
        ]);

        app(LedgerAggregator::class)->run();

        expect($user->fresh()->uploaded)->toBe(8_000)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });
});

describe('rebuild after migrating', function () {
    // The consequence the checkpoint singles out. A rebuild that zeroed a
    // user's pre-ledger ratio would be worse than the problem being fixed.
    test('rebuilds FROM the opening balance, not from zero', function () {
        $user = legacyUser('a@example.com', 5_000, 2_000);

        app(OpeningBalance::class)->record();
        app(LedgerAggregator::class)->run();

        $user->forceFill(['uploaded' => 999_999])->save();

        app(LedgerRebuilder::class)->rebuild();

        expect($user->fresh()->uploaded)->toBe(5_000)
            ->and($user->fresh()->downloaded)->toBe(2_000);
    });

    test('a rebuild preserves the balance alongside later announces', function () {
        $user = legacyUser('a@example.com', 5_000, 0);

        app(OpeningBalance::class)->record();

        AnnounceLog::create([
            'user_id' => $user->id,
            'torrent_id' => $this->torrent->id,
            'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'regular',
            'ip' => '10.0.0.1', 'port' => 51413,
            'uploaded' => 3_000, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => 3_000, 'download_delta' => 0,
            'prior_up' => 0, 'prior_down' => 0,
        ]);

        app(LedgerAggregator::class)->run();

        $user->forceFill(['uploaded' => 0])->save();

        app(LedgerRebuilder::class)->rebuild();

        expect($user->fresh()->uploaded)->toBe(8_000);
    });

    test('a scoped rebuild also keeps the balance', function () {
        $user = legacyUser('a@example.com', 5_000, 0);

        app(OpeningBalance::class)->record();
        app(LedgerAggregator::class)->run();

        $user->forceFill(['uploaded' => 0])->save();

        app(LedgerRebuilder::class)->rebuild($user->id);

        expect($user->fresh()->uploaded)->toBe(5_000);
    });
});

describe('the audit understands an opening balance', function () {
    // It has a null prior by design, so neither audit should flag it — a
    // migration artefact is not a broken chain.
    test('does not flag it as an arithmetic break', function () {
        legacyUser('a@example.com', 5_000, 2_000);

        app(OpeningBalance::class)->record();

        expect(app(LedgerAuditor::class)->audit()->arithmeticBreaks)->toBeEmpty();
    });

    test('does not flag it as a chain break', function () {
        legacyUser('a@example.com', 5_000, 2_000);

        app(OpeningBalance::class)->record();

        expect(app(LedgerAuditor::class)->audit()->chainBreaks)->toBeEmpty();
    });
});

// Recording a balance zeroes the column so the aggregator can put it back —
// otherwise the balance would be added on top of the figure it was copied
// from. That leaves a window where a user reads as 0, so the migration must
// close it rather than leaving people ratio-less until a scheduled tick.
describe('the zero window is closed by the migration', function () {
    test('the migration leaves totals restored, not zeroed', function () {
        $user = legacyUser('a@example.com', 5_000, 2_000);

        AnnounceLog::query()->delete();
        LedgerCursor::query()->delete();

        $migration = require __DIR__.'/../../database/migrations/2026_09_03_000004_record_opening_balances.php';
        $migration->up();

        expect($user->fresh()->uploaded)->toBe(5_000)
            ->and($user->fresh()->downloaded)->toBe(2_000);
    });

    test('and reconciliation is clean straight after the migration', function () {
        legacyUser('a@example.com', 5_000, 2_000);
        legacyUser('b@example.com', 900, 100);

        AnnounceLog::query()->delete();
        LedgerCursor::query()->delete();

        $migration = require __DIR__.'/../../database/migrations/2026_09_03_000004_record_opening_balances.php';
        $migration->up();

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });
});

describe('the migration runs it', function () {
    test('an install with pre-ledger totals gets balances written', function () {
        $user = legacyUser('a@example.com', 5_000, 2_000);

        // Simulate the upgrade path: totals exist, ledger is empty.
        AnnounceLog::query()->delete();
        LedgerCursor::query()->delete();

        $migration = require __DIR__.'/../../database/migrations/2026_09_03_000004_record_opening_balances.php';
        $migration->up();

        expect(AnnounceLog::where('user_id', $user->id)->count())->toBe(1);
    });

    test('it does not run on an install that already has ledger history', function () {
        $user = legacyUser('a@example.com', 5_000, 2_000);

        AnnounceLog::create([
            'user_id' => $user->id,
            'torrent_id' => $this->torrent->id,
            'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'regular',
            'ip' => '10.0.0.1', 'port' => 51413,
            'uploaded' => 1, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => 1, 'download_delta' => 0,
            'prior_up' => 0, 'prior_down' => 0,
        ]);

        $migration = require __DIR__.'/../../database/migrations/2026_09_03_000004_record_opening_balances.php';
        $migration->up();

        expect(AnnounceLog::where('event', AnnounceLog::EVENT_OPENING_BALANCE)->count())->toBe(0);
    });

    test('handles more users than one chunk', function () {
        for ($i = 0; $i < 120; $i++) {
            legacyUser("bulk{$i}@example.com", 1_000, 0);
        }

        app(OpeningBalance::class)->record(chunkSize: 50);

        expect(AnnounceLog::where('event', AnnounceLog::EVENT_OPENING_BALANCE)->count())->toBe(120);
    });
});
