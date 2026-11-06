<?php

declare(strict_types=1);

// Job #213 step 3: the two ways an operator corrects a member's totals.
//
// - accept: the stored totals are right and the ledger can't explain them
//   (an import, a 4.1.0 install, #11051). The gap is recorded as adjustments;
//   the totals don't move.
// - adjust: give or take a stated amount (a speed forfeit an operator decides
//   was wrong: that credit never reached the totals). The adjustment and the
//   totals move together.
//
// Either way reconcile is clean afterwards, a rebuild reproduces the result,
// and the decision is on record with who made it and why.

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerAdjustment;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAdjuster;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\StaffAction;

beforeEach(function () {
    $this->store = new class implements StaffActionsInterface
    {
        /** @var list<StaffAction> */
        public array $recorded = [];

        public function record(StaffAction $action): void
        {
            $this->recorded[] = $action;
        }
    };
    app()->instance(StaffActionsInterface::class, $this->store);

    $this->admin = TestUser::create(['name' => 'Admin', 'email' => 'adjuster-admin@example.com', 'password' => 'p', 'role' => 'admin']);
    $this->member = TestUser::create(['name' => 'Member', 'email' => 'adjuster-member@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create(['name' => 'One', 'info_hash' => str_repeat('d', 40), 'size' => 1_000, 'user_id' => $this->admin->id]);
    $this->second = Torrent::create(['name' => 'Two', 'info_hash' => str_repeat('e', 40), 'size' => 1_000, 'user_id' => $this->admin->id]);
});

function memberTotals(TestUser $user): array
{
    $row = DB::table($user->getTable())->where('id', $user->id)->first();

    return ['uploaded' => (int) $row->uploaded, 'downloaded' => (int) $row->downloaded, 'seedtime' => (int) $row->seedtime, 'leechtime' => (int) $row->leechtime];
}

function pairTotals(TestUser $user, int $torrentId): ?array
{
    return TorrentUser::query()->where('user_id', $user->id)->where('torrent_id', $torrentId)->first()
        ?->only(['uploaded', 'downloaded', 'seedtime', 'leechtime']);
}

function storeTotals(TestUser $user, ?Torrent $torrent, array $totals): void
{
    $totals += ['uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

    DB::table($user->getTable())->where('id', $user->id)->incrementEach($totals);

    if ($torrent !== null) {
        TorrentUser::query()->create(['user_id' => $user->id, 'torrent_id' => $torrent->id] + $totals);
    }
}

function folded(TestUser $user, Torrent $torrent, int $up): void
{
    AnnounceLog::create([
        'user_id' => $user->id, 'torrent_id' => $torrent->id, 'peer_id' => '-qB4210-adjusteradjus',
        'event' => 'regular', 'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => $up, 'downloaded' => 0,
        'left' => 0, 'upload_delta' => $up, 'download_delta' => 0,
    ]);
}

describe('accept', function () {
    it('records what the ledger cannot explain, per torrent, and leaves the totals alone', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000, 'seedtime' => 600]);
        storeTotals($this->member, $this->second, ['downloaded' => 2_000]);
        $before = memberTotals($this->member);

        $written = app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Imported from the old tracker');

        expect($written)->toHaveCount(2)
            ->and(LedgerAdjustment::query()->where('torrent_id', $this->torrent->id)->sole()->only(['uploaded', 'seedtime', 'made_by', 'reason']))
            ->toBe(['uploaded' => 5_000, 'seedtime' => 600, 'made_by' => $this->admin->id, 'reason' => 'Imported from the old tracker'])
            ->and(memberTotals($this->member))->toBe($before)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('is reproduced by a rebuild', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000, 'seedtime' => 600]);
        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import');
        $before = memberTotals($this->member);

        app(LedgerRebuilder::class)->rebuild();

        expect(memberTotals($this->member))->toBe($before)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    // A stored total BELOW its ledger is accepted too: the operator says the
    // stored figure is right, whichever way the gap runs.
    it('records a gap either way, signed', function () {
        folded($this->member, $this->torrent, 3_000);
        app(LedgerAggregator::class)->run();
        TorrentUser::query()->where('user_id', $this->member->id)->update(['uploaded' => 1_000]);
        DB::table($this->member->getTable())->where('id', $this->member->id)->update(['uploaded' => 1_000]);

        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'The 3,000 was a client bug');

        expect(LedgerAdjustment::query()->sole()->uploaded)->toBe(-2_000)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('puts what the member holds beyond their torrents on torrent 0', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 1_000]);
        DB::table($this->member->getTable())->where('id', $this->member->id)->increment('uploaded', 4_000);

        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Imported member-level figures');

        expect(LedgerAdjustment::query()->where('torrent_id', 0)->sole()->uploaded)->toBe(4_000)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

        app(LedgerRebuilder::class)->rebuild();

        expect(memberTotals($this->member)['uploaded'])->toBe(5_000)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    // A fold or a rebuild writes member-level credit to a torrent_user row on
    // torrent 0, which reconcile checks like any other. It moves with the
    // member-level adjustment, as a rebuild would move it.
    it('keeps an existing torrent-0 row in step with the member-level adjustment', function () {
        LedgerCarry::query()->create(['user_id' => $this->member->id, 'torrent_id' => 0, 'uploaded' => 500]);
        storeTotals($this->member, null, ['uploaded' => 500]);
        TorrentUser::query()->create(['user_id' => $this->member->id, 'torrent_id' => 0, 'uploaded' => 500]);
        DB::table($this->member->getTable())->where('id', $this->member->id)->increment('uploaded', 1_500);

        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import');

        expect(pairTotals($this->member, 0)['uploaded'])->toBe(2_000)
            ->and(memberTotals($this->member)['uploaded'])->toBe(2_000)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    // Member totals BELOW what their torrents add up to, with a torrent-0
    // row too small to absorb it: the projections contradict each other, and
    // a rebuild is the fix, not an operator's say-so.
    it('refuses when the torrent-0 row would go below zero, and writes nothing', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000]);
        TorrentUser::query()->create(['user_id' => $this->member->id, 'torrent_id' => 0, 'uploaded' => 100]);
        DB::table($this->member->getTable())->where('id', $this->member->id)->update(['uploaded' => 1_000]);

        expect(fn () => app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import'))
            ->toThrow(InvalidArgumentException::class, 'rebuild');

        expect(LedgerAdjustment::query()->count())->toBe(0);
    });

    // A backlog is not drift: rows the aggregator hasn't folded are in
    // neither the totals nor what reconcile expects, so accepting doesn't
    // count them, and folding them afterwards leaves reconcile clean.
    it('leaves unfolded rows to the aggregator', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000]);
        folded($this->member, $this->torrent, 700);

        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import');
        app(LedgerAggregator::class)->run();

        expect(LedgerAdjustment::query()->sole()->uploaded)->toBe(5_000)
            ->and(memberTotals($this->member)['uploaded'])->toBe(5_700)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('touches no other member', function () {
        $other = TestUser::create(['name' => 'Other', 'email' => 'adjuster-other@example.com', 'password' => 'p']);
        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000]);
        storeTotals($other, $this->torrent, ['uploaded' => 9_000]);

        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import');

        expect(LedgerAdjustment::query()->where('user_id', $other->id)->count())->toBe(0);
    });

    it('writes nothing, and records nothing, when the ledger already explains the totals', function () {
        folded($this->member, $this->torrent, 700);
        app(LedgerAggregator::class)->run();

        expect(app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import'))->toBe([])
            ->and(LedgerAdjustment::query()->count())->toBe(0)
            ->and($this->store->recorded)->toBe([]);
    });

    it('records one staff action, with who, whom, why and what the ledger now explains', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000]);

        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import');

        [$action] = $this->store->recorded;

        expect($this->store->recorded)->toHaveCount(1)
            ->and($action->action)->toBe('bloodhound.ledger.accepted')
            ->and($action->actorId)->toBe($this->admin->id)
            ->and($action->subjectId)->toBe($this->member->id)
            ->and($action->reason)->toBe('Import')
            ->and($action->changes)->toBe(['ledger_uploaded' => ['from' => 0, 'to' => 5_000]]);
    });
});

describe('adjust', function () {
    it('gives credit: the adjustment and the totals move together', function () {
        $row = app(LedgerAdjuster::class)->adjust($this->member->id, $this->torrent->id, ['uploaded' => 8_000], by: $this->admin->id, reason: 'Speed forfeit was a false positive');

        expect($row->only(['uploaded', 'torrent_id', 'made_by']))->toBe(['uploaded' => 8_000, 'torrent_id' => $this->torrent->id, 'made_by' => $this->admin->id])
            ->and(memberTotals($this->member)['uploaded'])->toBe(8_000)
            ->and(pairTotals($this->member, $this->torrent->id)['uploaded'])->toBe(8_000)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

        app(LedgerRebuilder::class)->rebuild();

        expect(memberTotals($this->member)['uploaded'])->toBe(8_000);
    });

    it('takes credit away', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000, 'seedtime' => 900]);
        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import');

        app(LedgerAdjuster::class)->adjust($this->member->id, $this->torrent->id, ['uploaded' => -2_000, 'seedtime' => -300], by: $this->admin->id, reason: 'Double-counted in the import');

        expect(memberTotals($this->member))->toMatchArray(['uploaded' => 3_000, 'seedtime' => 600])
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('adjusts the member as a whole on torrent 0', function () {
        app(LedgerAdjuster::class)->adjust($this->member->id, 0, ['uploaded' => 1_000], by: $this->admin->id, reason: 'Bonus for running a seedbox for us');

        expect(memberTotals($this->member)['uploaded'])->toBe(1_000)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('records a staff action with the totals before and after', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000]);
        app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: 'Import');

        app(LedgerAdjuster::class)->adjust($this->member->id, $this->torrent->id, ['uploaded' => 1_000], by: $this->admin->id, reason: 'Forfeit reversed');

        $action = end($this->store->recorded);

        expect($action->action)->toBe('bloodhound.ledger.adjusted')
            ->and($action->actorId)->toBe($this->admin->id)
            ->and($action->subjectId)->toBe($this->member->id)
            ->and($action->reason)->toBe('Forfeit reversed')
            ->and($action->changes)->toBe(['uploaded' => ['from' => 5_000, 'to' => 6_000]]);
    });

    // The totals are unsigned columns, and a negative ratio input is nonsense.
    it('refuses to take a total below zero, and writes nothing', function () {
        storeTotals($this->member, $this->torrent, ['uploaded' => 500]);

        expect(fn () => app(LedgerAdjuster::class)->adjust($this->member->id, $this->torrent->id, ['uploaded' => -600], by: $this->admin->id, reason: 'Too much'))
            ->toThrow(InvalidArgumentException::class, 'below zero');

        expect(LedgerAdjustment::query()->count())->toBe(0)
            ->and(memberTotals($this->member)['uploaded'])->toBe(500);
    });

    it('refuses an adjustment that moves nothing', function () {
        app(LedgerAdjuster::class)->adjust($this->member->id, $this->torrent->id, ['uploaded' => 0], by: $this->admin->id, reason: 'Nothing');
    })->throws(InvalidArgumentException::class);

    it('refuses a column that is not a total', function () {
        app(LedgerAdjuster::class)->adjust($this->member->id, $this->torrent->id, ['times_completed' => 1], by: $this->admin->id, reason: 'Nope');
    })->throws(InvalidArgumentException::class);

    it('refuses a torrent that does not exist', function () {
        app(LedgerAdjuster::class)->adjust($this->member->id, 999_999, ['uploaded' => 1], by: $this->admin->id, reason: 'Nope');
    })->throws(InvalidArgumentException::class, 'torrent');
});

// Nobody's correction goes unaccounted for: both refuse before writing anything.
describe('either way', function () {
    it('refuses without a reason', function (string $method) {
        $call = $method === 'accept'
            ? fn () => app(LedgerAdjuster::class)->accept($this->member->id, by: $this->admin->id, reason: '   ')
            : fn () => app(LedgerAdjuster::class)->adjust($this->member->id, 0, ['uploaded' => 1], by: $this->admin->id, reason: '   ');

        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000]);

        expect($call)->toThrow(InvalidArgumentException::class, 'reason')
            ->and(LedgerAdjustment::query()->count())->toBe(0);
    })->with(['accept', 'adjust']);

    it('refuses an author who is nobody', function (string $method) {
        $call = $method === 'accept'
            ? fn () => app(LedgerAdjuster::class)->accept($this->member->id, by: 999_999, reason: 'Import')
            : fn () => app(LedgerAdjuster::class)->adjust($this->member->id, 0, ['uploaded' => 1], by: 999_999, reason: 'Import');

        storeTotals($this->member, $this->torrent, ['uploaded' => 5_000]);

        expect($call)->toThrow(InvalidArgumentException::class, 'by')
            ->and(LedgerAdjustment::query()->count())->toBe(0);
    })->with(['accept', 'adjust']);

    it('refuses a member who is nobody', function (string $method) {
        $call = $method === 'accept'
            ? fn () => app(LedgerAdjuster::class)->accept(999_999, by: $this->admin->id, reason: 'Import')
            : fn () => app(LedgerAdjuster::class)->adjust(999_999, 0, ['uploaded' => 1], by: $this->admin->id, reason: 'Import');

        expect($call)->toThrow(InvalidArgumentException::class, 'member');
    })->with(['accept', 'adjust']);
});
