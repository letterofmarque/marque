<?php

declare(strict_types=1);

// Job #213 step 2: the ledger is its rows, its carry and its adjustments, and
// everything that asks what the ledger explains reads all three.
//
// Adjustments are seeded directly here, with the stored totals they explain;
// step 3's service writes them.

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\LedgerAdjustment;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Services\PrunedHistory;
use Marque\Bloodhound\Services\TimeOpeningBalance;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create(['name' => 'A', 'email' => 'adj-a@example.com', 'password' => 'p']);
    $this->other = TestUser::create(['name' => 'B', 'email' => 'adj-b@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create([
        'name' => 'One', 'info_hash' => str_repeat('c', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
});

/**
 * Stored totals no ledger row explains: what an import or a 4.1.0 install
 * leaves (per torrent, and on the member's own totals).
 */
function unexplained(TestUser $user, ?Torrent $torrent, int $uploaded = 0, int $seedtime = 0): void
{
    DB::table($user->getTable())->where('id', $user->id)->incrementEach(['uploaded' => $uploaded, 'seedtime' => $seedtime]);

    if ($torrent !== null) {
        $row = TorrentUser::query()->firstOrNew(['user_id' => $user->id, 'torrent_id' => $torrent->id]);
        $row->uploaded = (int) $row->uploaded + $uploaded;
        $row->seedtime = (int) $row->seedtime + $seedtime;
        $row->save();
    }
}

function adjusted(TestUser $user, int $torrentId, int $uploaded = 0, int $seedtime = 0): void
{
    LedgerAdjustment::query()->create([
        'user_id' => $user->id,
        'torrent_id' => $torrentId,
        'uploaded' => $uploaded,
        'seedtime' => $seedtime,
        'reason' => 'Imported from the old tracker',
        'made_by' => 1,
    ]);
}

function storedTotals(TestUser $user): array
{
    $row = DB::table($user->getTable())->where('id', $user->id)->first();

    return [
        'user' => [(int) $row->uploaded, (int) $row->seedtime],
        'torrents' => TorrentUser::query()->where('user_id', $user->id)->where('torrent_id', '!=', 0)->orderBy('torrent_id')
            ->get()->map(fn ($r) => [$r->torrent_id, $r->uploaded, $r->seedtime])->all(),
    ];
}

describe('reconcile', function () {
    it('reports totals no row explains as drift, until an adjustment explains them', function () {
        unexplained($this->user, $this->torrent, uploaded: 5_000, seedtime: 600);

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeTrue();

        adjusted($this->user, $this->torrent->id, uploaded: 5_000, seedtime: 600);

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('counts a member-level adjustment towards their own totals only', function () {
        DB::table($this->user->getTable())->where('id', $this->user->id)->update(['uploaded' => 2_000]);
        adjusted($this->user, 0, uploaded: 2_000);

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('adds adjustments to the carry, and sums several for one pair', function () {
        unexplained($this->user, $this->torrent, uploaded: 1_000);
        LedgerCarry::query()->create(['user_id' => $this->user->id, 'torrent_id' => $this->torrent->id, 'uploaded' => 400]);
        adjusted($this->user, $this->torrent->id, uploaded: 800);
        adjusted($this->user, $this->torrent->id, uploaded: -200);

        expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('still reports drift beyond the adjustment', function () {
        unexplained($this->user, $this->torrent, uploaded: 5_000);
        adjusted($this->user, $this->torrent->id, uploaded: 4_000);

        $report = app(LedgerAuditor::class)->reconcile();

        expect(collect($report->torrentUserDrift)->firstWhere('column', 'uploaded'))->toMatchArray(['recorded' => 5_000, 'expected' => 4_000]);
    });
});

describe('rebuild', function () {
    it('reproduces adjusted totals instead of erasing them', function () {
        unexplained($this->user, $this->torrent, uploaded: 5_000, seedtime: 600);
        adjusted($this->user, $this->torrent->id, uploaded: 5_000, seedtime: 600);
        $before = storedTotals($this->user);

        app(LedgerRebuilder::class)->rebuild();

        expect(storedTotals($this->user))->toBe($before)
            ->and($before['user'])->toBe([5_000, 600]);
    });

    it('reproduces a member-level adjustment on the member', function () {
        DB::table($this->user->getTable())->where('id', $this->user->id)->update(['uploaded' => 2_000]);
        adjusted($this->user, 0, uploaded: 2_000);

        app(LedgerRebuilder::class)->rebuild();

        expect(storedTotals($this->user)['user'])->toBe([2_000, 0])
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('applies a negative adjustment', function () {
        adjusted($this->user, $this->torrent->id, uploaded: 3_000);
        adjusted($this->user, $this->torrent->id, uploaded: -1_000);

        app(LedgerRebuilder::class)->rebuild();

        expect(storedTotals($this->user)['user'])->toBe([2_000, 0]);
    });

    it('restores a pair held only by an adjustment, even after its row is lost', function () {
        unexplained($this->user, $this->torrent, uploaded: 5_000);
        adjusted($this->user, $this->torrent->id, uploaded: 5_000);

        TorrentUser::query()->delete();
        app(LedgerRebuilder::class)->rebuild();

        expect(storedTotals($this->user)['torrents'])->toBe([[$this->torrent->id, 5_000, 0]]);
    });

    it('counts only that member\'s adjustments in a scoped rebuild', function () {
        adjusted($this->user, $this->torrent->id, uploaded: 700);
        adjusted($this->other, $this->torrent->id, uploaded: 900);
        DB::table($this->user->getTable())->update(['uploaded' => 1]);

        app(LedgerRebuilder::class)->rebuild($this->user->id);

        expect(storedTotals($this->user)['user'][0])->toBe(700)
            ->and(storedTotals($this->other)['user'][0])->toBe(1);
    });
});

// The two upgrade-time gap finders carry whatever the stored totals hold
// beyond what the ledger explains. An adjustment is part of what it explains:
// counting without it would carry the same credit a second time.
describe('the gap finders', function () {
    it('pruned-history records nothing an adjustment already explains', function () {
        unexplained($this->user, $this->torrent, uploaded: 5_000);
        adjusted($this->user, $this->torrent->id, uploaded: 5_000);

        $recorded = app(PrunedHistory::class)->record();

        expect($recorded['rows'])->toBe(0)
            ->and(LedgerCarry::query()->count())->toBe(0);
    });

    it('the time opening balance records nothing an adjustment already explains', function () {
        unexplained($this->user, $this->torrent, seedtime: 600);
        adjusted($this->user, $this->torrent->id, seedtime: 600);

        app(TimeOpeningBalance::class)->record();

        expect(LedgerCarry::query()->count())->toBe(0)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });
});

// An upgrade's own migrations run the gap finders (record_pruned_history,
// record_time_opening_balances) before ledger_adjustments is created. No
// adjustment can exist yet, so its absence means none, not an error.
it('reads carry alone where the adjustments table does not exist yet', function () {
    Illuminate\Support\Facades\Schema::connection(config('bloodhound.announce_log.connection'))->drop('ledger_adjustments');
    LedgerCarry::query()->create(['user_id' => $this->user->id, 'torrent_id' => $this->torrent->id, 'uploaded' => 400]);

    expect(app(Marque\Bloodhound\Services\LedgerExtras::class)->perPair())
        ->toHaveKey("{$this->user->id}:{$this->torrent->id}")
        ->and(app(PrunedHistory::class)->record()['rows'])->toBe(0);
});
