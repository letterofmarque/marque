<?php

declare(strict_types=1);

// CP4 of Build #92 (Spec #99 — the announce ledger).
//
// Per-user-per-torrent accounting, which did not exist. Deltas were aggregated
// onto the user (all torrents combined) and onto Redis swarm totals (all users
// combined); the intersection was computed every announce and thrown away.
//
// This table replaces `snatches`, which had two problems: nothing ever read it,
// and updateOrCreate overwrote completed_at on a redownload, destroying the
// original completion date. Hit-and-run rules measuring "seed for N days after
// completing" would have measured from the wrong date.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'tu@example.com',
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

describe('schema', function () {
    test('the table exists with the columns the projection needs', function () {
        expect(Schema::hasTable('torrent_user'))->toBeTrue();

        foreach ([
            'user_id', 'torrent_id', 'uploaded', 'downloaded', 'seedtime',
            'first_completed_at', 'last_completed_at', 'times_completed',
        ] as $column) {
            expect(Schema::hasColumn('torrent_user', $column))->toBeTrue();
        }
    });

    test('a user has at most one row per torrent', function () {
        TorrentUser::create(['user_id' => $this->user->id, 'torrent_id' => $this->torrent->id]);

        expect(fn () => TorrentUser::create([
            'user_id' => $this->user->id,
            'torrent_id' => $this->torrent->id,
        ]))->toThrow(Exception::class);
    });

    test('byte counters and completions default to zero', function () {
        $row = TorrentUser::create([
            'user_id' => $this->user->id,
            'torrent_id' => $this->torrent->id,
        ])->fresh();

        expect($row->uploaded)->toBe(0)
            ->and($row->downloaded)->toBe(0)
            ->and($row->seedtime)->toBe(0)
            ->and($row->times_completed)->toBe(0)
            ->and($row->first_completed_at)->toBeNull()
            ->and($row->last_completed_at)->toBeNull();
    });
});

describe('recording a completion', function () {
    test('sets both dates and counts one completion', function () {
        Carbon::setTestNow('2026-01-01 10:00:00');

        $row = TorrentUser::recordCompletion($this->user->id, $this->torrent->id);

        expect($row->times_completed)->toBe(1)
            ->and($row->first_completed_at->toDateTimeString())->toBe('2026-01-01 10:00:00')
            ->and($row->last_completed_at->toDateTimeString())->toBe('2026-01-01 10:00:00');

        Carbon::setTestNow();
    });

    // The bug this table exists to fix. snatches used updateOrCreate, so a
    // redownload six months later overwrote the January date with July and the
    // original was gone.
    test('a redownload never overwrites the first completion date', function () {
        Carbon::setTestNow('2026-01-01 10:00:00');
        TorrentUser::recordCompletion($this->user->id, $this->torrent->id);

        Carbon::setTestNow('2026-07-01 10:00:00');
        $row = TorrentUser::recordCompletion($this->user->id, $this->torrent->id);

        expect($row->first_completed_at->toDateTimeString())->toBe('2026-01-01 10:00:00')
            ->and($row->last_completed_at->toDateTimeString())->toBe('2026-07-01 10:00:00')
            ->and($row->times_completed)->toBe(2);

        Carbon::setTestNow();
    });

    test('still keeps one row per user per torrent', function () {
        TorrentUser::recordCompletion($this->user->id, $this->torrent->id);
        TorrentUser::recordCompletion($this->user->id, $this->torrent->id);
        TorrentUser::recordCompletion($this->user->id, $this->torrent->id);

        expect(TorrentUser::count())->toBe(1);
    });

    // Dan's corrupt-quarter case: a 4GB torrent whose third file was bad, so
    // the user refetched 1GB and the client fired `completed` again. Two
    // completions, ~5GB transferred — not 8GB. Completion count and byte
    // volume are independent and neither can be derived from the other.
    test('completion count says nothing about bytes transferred', function () {
        $row = TorrentUser::recordCompletion($this->user->id, $this->torrent->id);
        $row->forceFill(['downloaded' => 4_000_000_000])->save();

        $row = TorrentUser::recordCompletion($this->user->id, $this->torrent->id);
        $row->forceFill(['downloaded' => 5_000_000_000])->save();

        $row = $row->fresh();

        expect($row->times_completed)->toBe(2)
            ->and($row->downloaded)->toBe(5_000_000_000);
    });

    test('different users on one torrent get their own rows', function () {
        $other = TestUser::create([
            'name' => 'Other', 'email' => 'other@example.com', 'password' => 'p',
            'announce_key' => str_repeat('b', 32),
        ]);

        TorrentUser::recordCompletion($this->user->id, $this->torrent->id);
        TorrentUser::recordCompletion($other->id, $this->torrent->id);

        expect(TorrentUser::count())->toBe(2);
    });
});

describe('snatches is gone', function () {
    test('the table no longer exists', function () {
        expect(Schema::hasTable('snatches'))->toBeFalse();
    });

    test('a completed announce records into torrent_user instead', function () {
        event(new TorrentCompleted(
            userId: $this->user->id,
            torrentId: $this->torrent->id,
            ip: '10.0.0.1',
            userAgent: 'qBittorrent/4.5.0',
        ));

        $row = TorrentUser::first();

        expect($row)->not->toBeNull()
            ->and($row->user_id)->toBe($this->user->id)
            ->and($row->torrent_id)->toBe($this->torrent->id)
            ->and($row->times_completed)->toBe(1);
    });
});
