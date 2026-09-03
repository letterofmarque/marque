<?php

declare(strict_types=1);

// CP7 of Build #92 (Spec #99 — the announce ledger).
//
// The checkpoint the whole Spec exists for. Everything before this made the
// data durable; none of it made a wrong number *detectable*. A ratio that
// silently drifts is the failure being designed against, and durability alone
// does not address it.
//
// Three mechanisms:
//   - reconciliation: projections vs SUM over the ledger, drift reported loudly
//   - rebuild: recompute projections from the ledger, one user or all
//   - audit: per-row arithmetic, and the per-peer chain that makes a past
//     Redis loss visible after the fact

use Illuminate\Console\Scheduling\Schedule;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'recon@example.com',
        'password' => 'password',
        'announce_key' => 'aaaabbbbccccddddeeeeffffgggghhhh',
    ]);

    $this->torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => str_repeat('a', 40),
        'size' => 5_000_000_000,
        'user_id' => $this->user->id,
    ]);
});

/**
 * A ledger row whose arithmetic is internally consistent by default.
 */
function auditRow(
    int $userId,
    int $torrentId,
    int $priorUp,
    int $reportedUp,
    ?int $deltaUp = null,
    string $peerId = '-qB4210-aaaaaaaaaaaa',
    string $event = 'regular',
): AnnounceLog {
    return AnnounceLog::create([
        'user_id' => $userId,
        'torrent_id' => $torrentId,
        'peer_id' => $peerId,
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => $reportedUp,
        'downloaded' => 0,
        'left' => 0,
        'upload_delta' => $deltaUp ?? ($reportedUp - $priorUp),
        'download_delta' => 0,
        'prior_up' => $priorUp,
        'prior_down' => 0,
    ]);
}

describe('reconciliation', function () {
    test('reports no drift on a healthy install', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();

        $report = app(LedgerAuditor::class)->reconcile();

        expect($report->hasDrift())->toBeFalse()
            ->and($report->userDrift)->toBeEmpty();
    });

    // The test this checkpoint exists for. A number that silently went wrong
    // must become a number someone is told about.
    test('detects a user total that has drifted from the ledger', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();

        // Something corrupted it — a lost job under the old design, a bad
        // manual edit, a bug. The cause does not matter; being told does.
        $this->user->forceFill(['uploaded' => 999])->save();

        $report = app(LedgerAuditor::class)->reconcile();

        expect($report->hasDrift())->toBeTrue()
            ->and($report->userDrift)->toHaveCount(1);

        $drift = $report->userDrift[0];

        expect($drift['user_id'])->toBe($this->user->id)
            ->and($drift['recorded'])->toBe(999)
            ->and($drift['expected'])->toBe(1_000);
    });

    test('detects a per-torrent total that has drifted', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();

        TorrentUser::first()->forceFill(['uploaded' => 5])->save();

        $report = app(LedgerAuditor::class)->reconcile();

        expect($report->hasDrift())->toBeTrue()
            ->and($report->torrentUserDrift)->toHaveCount(1);
    });

    // Only rows the aggregator has actually consumed count as expected. A
    // backlog is not drift, and reporting it as such would make the alerting
    // noise — which is how a detection mechanism gets ignored.
    test('unaggregated rows are a backlog, not drift', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();

        auditRow($this->user->id, $this->torrent->id, 1_000, 4_000);

        $report = app(LedgerAuditor::class)->reconcile();

        expect($report->hasDrift())->toBeFalse()
            ->and($report->pending)->toBe(1);
    });

    test('the command exits non-zero when drift is found', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();
        $this->user->forceFill(['uploaded' => 999])->save();

        $this->artisan('bloodhound:reconcile-ledger')->assertFailed();
    });

    test('the command exits zero when everything agrees', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();

        $this->artisan('bloodhound:reconcile-ledger')->assertSuccessful();
    });

    test('it is scheduled', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'bloodhound:reconcile-ledger'));

        expect($events)->toHaveCount(1);
    });
});

describe('rebuild', function () {
    // Must exist before an incident needs it, not be written during one.
    test('restores a corrupted user total from the ledger', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        auditRow($this->user->id, $this->torrent->id, 1_000, 4_000);
        app(LedgerAggregator::class)->run();

        $this->user->forceFill(['uploaded' => 0])->save();

        app(LedgerRebuilder::class)->rebuild();

        expect($this->user->fresh()->uploaded)->toBe(4_000);
    });

    test('restores a corrupted per-torrent total', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();

        TorrentUser::first()->forceFill(['uploaded' => 999_999])->save();

        app(LedgerRebuilder::class)->rebuild();

        expect(TorrentUser::first()->uploaded)->toBe(1_000);
    });

    test('leaves reconciliation clean afterwards', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();
        $this->user->forceFill(['uploaded' => 42])->save();

        app(LedgerRebuilder::class)->rebuild();

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    test('can be scoped to one user', function () {
        $other = TestUser::create([
            'name' => 'Other', 'email' => 'o@example.com', 'password' => 'p',
            'announce_key' => str_repeat('c', 32),
        ]);

        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        auditRow($other->id, $this->torrent->id, 0, 2_000, peerId: '-qB4210-bbbbbbbbbbbb');
        app(LedgerAggregator::class)->run();

        $this->user->forceFill(['uploaded' => 0])->save();
        $other->forceFill(['uploaded' => 0])->save();

        app(LedgerRebuilder::class)->rebuild($this->user->id);

        expect($this->user->fresh()->uploaded)->toBe(1_000)
            ->and($other->fresh()->uploaded)->toBe(0);
    });

    // A rebuild that silently zeroed someone would be worse than the drift it
    // is fixing, so the cursor has to end up where the ledger says.
    test('leaves the cursor consistent with what it rebuilt', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        $last = auditRow($this->user->id, $this->torrent->id, 1_000, 4_000);
        app(LedgerAggregator::class)->run();

        app(LedgerRebuilder::class)->rebuild();

        expect(LedgerCursor::positionFor(LedgerAggregator::STREAM))->toBe($last->id);
    });

    test('the command runs', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        app(LedgerAggregator::class)->run();
        $this->user->forceFill(['uploaded' => 0])->save();

        $this->artisan('bloodhound:rebuild-totals')->assertSuccessful();

        expect($this->user->fresh()->uploaded)->toBe(1_000);
    });
});

describe('per-row arithmetic audit', function () {
    test('passes a row whose delta matches its own inputs', function () {
        auditRow($this->user->id, $this->torrent->id, 1_000, 5_000);

        expect(app(LedgerAuditor::class)->audit()->arithmeticBreaks)->toBeEmpty();
    });

    test('flags a row whose delta does not match reported minus prior', function () {
        auditRow($this->user->id, $this->torrent->id, 1_000, 5_000, deltaUp: 9_999);

        $breaks = app(LedgerAuditor::class)->audit()->arithmeticBreaks;

        expect($breaks)->toHaveCount(1)
            ->and($breaks[0]['upload_delta'])->toBe(9_999)
            ->and($breaks[0]['expected_upload_delta'])->toBe(4_000);
    });

    // A null prior is a first announce, not a broken row.
    test('does not flag a first announce with no baseline', function () {
        AnnounceLog::create([
            'user_id' => $this->user->id,
            'torrent_id' => $this->torrent->id,
            'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'started',
            'ip' => '10.0.0.1', 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => 0, 'download_delta' => 0,
            'prior_up' => null, 'prior_down' => null,
        ]);

        expect(app(LedgerAuditor::class)->audit()->arithmeticBreaks)->toBeEmpty();
    });
});

describe('per-peer chain audit', function () {
    test('passes an unbroken chain', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        auditRow($this->user->id, $this->torrent->id, 1_000, 4_000);
        auditRow($this->user->id, $this->torrent->id, 4_000, 9_000);

        expect(app(LedgerAuditor::class)->audit()->chainBreaks)->toBeEmpty();
    });

    // This is what makes a past Redis outage visible after the fact. A row
    // whose prior does not match the previous row's reported value for that
    // peer means a baseline went missing between them.
    test('flags a chain break where a baseline went missing', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        auditRow($this->user->id, $this->torrent->id, 1_000, 4_000);

        // Should have diffed against 4_000. It did not — a baseline was lost.
        auditRow($this->user->id, $this->torrent->id, 0, 9_000);

        $breaks = app(LedgerAuditor::class)->audit()->chainBreaks;

        expect($breaks)->toHaveCount(1)
            ->and($breaks[0]['prior_up'])->toBe(0)
            ->and($breaks[0]['previous_uploaded'])->toBe(4_000);
    });

    test('a null prior mid-chain is a break, since a baseline existed', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);

        AnnounceLog::create([
            'user_id' => $this->user->id,
            'torrent_id' => $this->torrent->id,
            'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'regular',
            'ip' => '10.0.0.1', 'port' => 51413,
            'uploaded' => 5_000, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => 0, 'download_delta' => 0,
            'prior_up' => null, 'prior_down' => null,
        ]);

        expect(app(LedgerAuditor::class)->audit()->chainBreaks)->toHaveCount(1);
    });

    // Chains are per (torrent, peer). Separate peers are separate chains and
    // must not be compared against each other.
    test('does not compare across peers', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000, peerId: '-qB4210-aaaaaaaaaaaa');
        auditRow($this->user->id, $this->torrent->id, 0, 7_000, peerId: '-qB4210-bbbbbbbbbbbb');

        expect(app(LedgerAuditor::class)->audit()->chainBreaks)->toBeEmpty();
    });

    test('does not compare across torrents', function () {
        $other = Torrent::create([
            'name' => 'Other', 'info_hash' => str_repeat('b', 40),
            'size' => 1_000, 'user_id' => $this->user->id,
        ]);

        auditRow($this->user->id, $this->torrent->id, 0, 1_000);
        auditRow($this->user->id, $other->id, 0, 7_000);

        expect(app(LedgerAuditor::class)->audit()->chainBreaks)->toBeEmpty();
    });

    test('the audit command reports breaks and exits non-zero', function () {
        auditRow($this->user->id, $this->torrent->id, 1_000, 5_000, deltaUp: 9_999);

        $this->artisan('bloodhound:audit-ledger')->assertFailed();
    });

    test('the audit command exits zero on a clean ledger', function () {
        auditRow($this->user->id, $this->torrent->id, 0, 1_000);

        $this->artisan('bloodhound:audit-ledger')->assertSuccessful();
    });
});
