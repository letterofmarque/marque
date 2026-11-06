<?php

declare(strict_types=1);

// Job #213 step 4: the operator's commands. Never silent: each shows what it
// will write and asks before writing; --force is how a script says yes.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marque\Bloodhound\Models\LedgerAdjustment;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->admin = TestUser::create(['name' => 'Admin', 'email' => 'cmd-admin@example.com', 'password' => 'p', 'role' => 'admin']);
    $this->member = TestUser::create(['name' => 'Member', 'email' => 'cmd-member@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create(['name' => 'One', 'info_hash' => str_repeat('f', 40), 'size' => 1_000, 'user_id' => $this->admin->id]);
});

function importedTotals(TestUser $user, Torrent $torrent, int $uploaded): void
{
    DB::table($user->getTable())->where('id', $user->id)->increment('uploaded', $uploaded);
    TorrentUser::query()->create(['user_id' => $user->id, 'torrent_id' => $torrent->id, 'uploaded' => $uploaded]);
}

describe('bloodhound:accept-totals', function () {
    it('shows what it will record, asks, and records it', function () {
        importedTotals($this->member, $this->torrent, 5_000);

        $this->artisan('bloodhound:accept-totals', ['--user' => $this->member->id, '--by' => $this->admin->id, '--reason' => 'Imported'])
            ->expectsOutputToContain('5000')
            ->expectsConfirmation('Record these as correct?', 'yes')
            ->expectsOutputToContain('Recorded 1 adjustment(s) for 1 member(s)')
            ->assertSuccessful();

        expect(LedgerAdjustment::query()->sole()->uploaded)->toBe(5_000)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('writes nothing when the operator says no', function () {
        importedTotals($this->member, $this->torrent, 5_000);

        $this->artisan('bloodhound:accept-totals', ['--user' => $this->member->id, '--by' => $this->admin->id, '--reason' => 'Imported'])
            ->expectsConfirmation('Record these as correct?', 'no')
            ->expectsOutputToContain('Nothing written')
            ->assertFailed();

        expect(LedgerAdjustment::query()->count())->toBe(0);
    });

    it('writes without asking under --force, and logs each member', function () {
        Log::spy();
        importedTotals($this->member, $this->torrent, 5_000);

        $this->artisan('bloodhound:accept-totals', ['--user' => $this->member->id, '--by' => $this->admin->id, '--reason' => 'Imported', '--force' => true])
            ->assertSuccessful();

        expect(LedgerAdjustment::query()->count())->toBe(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, "user {$this->member->id}") && str_contains($message, 'Imported'));
    });

    // The #11051 case: every member a 4.1.0 install left with totals the
    // ledger can't explain, at once, and nobody whose totals already agree.
    it('accepts every member reconcile reports, under --all', function () {
        $other = TestUser::create(['name' => 'Other', 'email' => 'cmd-other@example.com', 'password' => 'p']);
        $clean = TestUser::create(['name' => 'Clean', 'email' => 'cmd-clean@example.com', 'password' => 'p']);
        importedTotals($this->member, $this->torrent, 5_000);
        importedTotals($other, $this->torrent, 7_000);

        $this->artisan('bloodhound:accept-totals', ['--all' => true, '--by' => $this->admin->id, '--reason' => '4.1.0 queued log', '--force' => true])
            ->expectsOutputToContain('Recorded 2 adjustment(s) for 2 member(s)')
            ->assertSuccessful();

        expect(LedgerAdjustment::query()->where('user_id', $clean->id)->count())->toBe(0)
            ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
    });

    it('says so when there is nothing to accept', function () {
        $this->artisan('bloodhound:accept-totals', ['--user' => $this->member->id, '--by' => $this->admin->id, '--reason' => 'Imported'])
            ->expectsOutputToContain('nothing to accept')
            ->assertSuccessful();
    });

    it('refuses without --user or --all, or with both', function (array $scope) {
        $this->artisan('bloodhound:accept-totals', $scope + ['--by' => $this->admin->id, '--reason' => 'r'])
            ->expectsOutputToContain('--user')
            ->assertFailed();
    })->with([
        'neither' => [[]],
        'both' => [['--user' => 1, '--all' => true]],
    ]);

    it('refuses without a reason, or an author who exists', function (array $options, string $says) {
        importedTotals($this->member, $this->torrent, 5_000);

        $this->artisan('bloodhound:accept-totals', $options + ['--user' => $this->member->id, '--force' => true])
            ->expectsOutputToContain($says)
            ->assertFailed();

        expect(LedgerAdjustment::query()->count())->toBe(0);
    })->with([
        'no reason' => [['--by' => 1], '--reason'],
        'no author' => [['--reason' => 'r'], '--by'],
        'nobody' => [['--reason' => 'r', '--by' => 999_999], '--by'],
    ]);
});

describe('bloodhound:adjust-totals', function () {
    it('shows the change, asks, and makes it', function () {
        importedTotals($this->member, $this->torrent, 5_000);

        $this->artisan('bloodhound:adjust-totals', [
            'user' => $this->member->id, '--torrent' => $this->torrent->id, '--uploaded' => '8000',
            '--by' => $this->admin->id, '--reason' => 'Forfeit was a false positive',
        ])
            ->expectsOutputToContain('5000 → 13000')
            ->expectsConfirmation('Make this adjustment?', 'yes')
            ->assertSuccessful();

        expect((int) DB::table($this->member->getTable())->where('id', $this->member->id)->value('uploaded'))->toBe(13_000);
    });

    it('takes a negative amount', function () {
        importedTotals($this->member, $this->torrent, 5_000);

        $this->artisan('bloodhound:adjust-totals', [
            'user' => $this->member->id, '--torrent' => $this->torrent->id, '--uploaded' => '-1000', '--by' => $this->admin->id, '--reason' => 'Double-counted', '--force' => true,
        ])->assertSuccessful();

        expect(LedgerAdjustment::query()->sole()->only(['torrent_id', 'uploaded']))->toBe(['torrent_id' => $this->torrent->id, 'uploaded' => -1_000]);
    });

    it('adjusts the member as a whole by default', function () {
        $this->artisan('bloodhound:adjust-totals', [
            'user' => $this->member->id, '--uploaded' => '1000', '--by' => $this->admin->id, '--reason' => 'Bonus', '--force' => true,
        ])->assertSuccessful();

        expect(LedgerAdjustment::query()->sole()->torrent_id)->toBe(0);
    });

    // Credit held on a torrent is taken from that torrent: torrent 0 can't go
    // negative, or a rebuild would write a negative row.
    it('refuses to debit the member as a whole below what torrent 0 holds, and says where to debit', function () {
        importedTotals($this->member, $this->torrent, 5_000);

        $this->artisan('bloodhound:adjust-totals', [
            'user' => $this->member->id, '--uploaded' => '-1000', '--by' => $this->admin->id, '--reason' => 'r', '--force' => true,
        ])->expectsOutputToContain('--torrent')->assertFailed();

        expect(LedgerAdjustment::query()->count())->toBe(0);
    });

    it('refuses an amount that is not a whole number', function () {
        $this->artisan('bloodhound:adjust-totals', [
            'user' => $this->member->id, '--uploaded' => '5G', '--by' => $this->admin->id, '--reason' => 'r', '--force' => true,
        ])->expectsOutputToContain('whole number')->assertFailed();
    });

    it('passes on what the service refuses, and writes nothing', function () {
        $this->artisan('bloodhound:adjust-totals', [
            'user' => $this->member->id, '--uploaded' => '-1', '--by' => $this->admin->id, '--reason' => 'r', '--force' => true,
        ])->expectsOutputToContain('below zero')->assertFailed();

        expect(LedgerAdjustment::query()->count())->toBe(0);
    });
});

describe('bloodhound:ledger-adjustments', function () {
    it('lists who, when, why and how much', function () {
        importedTotals($this->member, $this->torrent, 5_000);
        $this->artisan('bloodhound:accept-totals', ['--user' => $this->member->id, '--by' => $this->admin->id, '--reason' => 'Imported from TBDev', '--force' => true]);

        // One capture: the amount and the reason share a table line, and each
        // expectsOutputToContain() consumes the write it matches.
        expect(Illuminate\Support\Facades\Artisan::call('bloodhound:ledger-adjustments', ['--user' => $this->member->id]))->toBe(0);

        expect(Illuminate\Support\Facades\Artisan::output())
            ->toContain('Imported from TBDev')
            ->toContain('5000')
            ->toContain((string) $this->admin->id);
    });

    it('says so when there are none', function () {
        $this->artisan('bloodhound:ledger-adjustments')
            ->expectsOutputToContain('No ledger adjustments')
            ->assertSuccessful();
    });
});
