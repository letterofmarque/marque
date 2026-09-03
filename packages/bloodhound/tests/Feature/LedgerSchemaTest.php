<?php

declare(strict_types=1);

// CP1 of Build #92 (Spec #99 — the announce ledger).
//
// Schema and config only. The write path still behaves exactly as it did;
// what changes here is that the table can now record the baseline a delta was
// computed against, and that logging is on by default because a source of
// truth cannot be opt-in.

use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Threepio\Enums\AnnounceEvent;

describe('prior_up / prior_down', function () {
    test('the columns exist on announce_log', function () {
        expect(Schema::hasColumn('announce_log', 'prior_up'))->toBeTrue()
            ->and(Schema::hasColumn('announce_log', 'prior_down'))->toBeTrue();
    });

    // Null is a real, expected state — a peer announcing for the first time has
    // no baseline to diff against — not missing data.
    //
    // Asserts the columns are actually present first: without that, mass
    // assignment silently drops unknown keys and reading back null would pass
    // even with no columns at all.
    test('they are nullable, for a peer session with no baseline', function () {
        expect(Schema::hasColumn('announce_log', 'prior_up'))->toBeTrue();

        $row = AnnounceLog::create([
            'user_id' => 1,
            'torrent_id' => 1,
            'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'started',
            'ip' => '10.0.0.1',
            'port' => 51413,
            'uploaded' => 0,
            'downloaded' => 0,
            'left' => 1_000,
            'upload_delta' => 0,
            'download_delta' => 0,
            'prior_up' => null,
            'prior_down' => null,
        ]);

        expect($row->fresh()->prior_up)->toBeNull()
            ->and($row->fresh()->prior_down)->toBeNull();
    });

    test('they round-trip the baseline a delta was computed against', function () {
        $row = AnnounceLog::create([
            'user_id' => 1,
            'torrent_id' => 1,
            'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'regular',
            'ip' => '10.0.0.1',
            'port' => 51413,
            'uploaded' => 5_000,
            'downloaded' => 9_000,
            'left' => 0,
            'upload_delta' => 2_000,
            'download_delta' => 3_000,
            'prior_up' => 3_000,
            'prior_down' => 6_000,
        ]);

        $fresh = $row->fresh();

        expect($fresh->prior_up)->toBe(3_000)
            ->and($fresh->prior_down)->toBe(6_000);
    });

    // The whole point of storing prior: a row carries its own arithmetic proof,
    // so `delta == reported - prior` is checkable per row without reference to
    // any other state. CP7 builds the audit that relies on this.
    test('a row carries enough to verify its own arithmetic', function () {
        $row = AnnounceLog::create([
            'user_id' => 1,
            'torrent_id' => 1,
            'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'regular',
            'ip' => '10.0.0.1',
            'port' => 51413,
            'uploaded' => 5_000,
            'downloaded' => 9_000,
            'left' => 0,
            'upload_delta' => 2_000,
            'download_delta' => 3_000,
            'prior_up' => 3_000,
            'prior_down' => 6_000,
        ])->fresh();

        expect($row->uploaded - $row->prior_up)->toBe($row->upload_delta)
            ->and($row->downloaded - $row->prior_down)->toBe($row->download_delta);
    });
});

describe('opening_balance event', function () {
    // Deliberately NOT added to threepio's AnnounceEvent enum: that enum is the
    // BitTorrent protocol's event set, and an opening balance is a migration
    // artefact, not something a client ever sends.
    test('is a bloodhound constant, not a protocol announce event', function () {
        expect(AnnounceLog::EVENT_OPENING_BALANCE)->toBe('opening_balance')
            ->and(enum_exists(AnnounceEvent::class))->toBeTrue()
            ->and(AnnounceEvent::tryFrom('opening_balance'))->toBeNull();
    });

    test('the event column accepts it', function () {
        $row = AnnounceLog::create([
            'user_id' => 1,
            'torrent_id' => 1,
            'peer_id' => '',
            'event' => AnnounceLog::EVENT_OPENING_BALANCE,
            'ip' => '0.0.0.0',
            'port' => 0,
            'uploaded' => 500_000,
            'downloaded' => 250_000,
            'left' => 0,
            'upload_delta' => 500_000,
            'download_delta' => 250_000,
            'prior_up' => null,
            'prior_down' => null,
        ]);

        expect($row->fresh()->event)->toBe('opening_balance');
    });
});

describe('logging default', function () {
    // Inverted from Spec #98. #98 shipped this off because it was an optional
    // investigative extra; Spec #99 makes it the source of truth for ratio, and
    // a source of truth cannot be optional.
    test('announce_log is enabled by default', function () {
        expect(config('bloodhound.announce_log.enabled'))->toBeTrue();
    });

    test('retention still defaults to keeping everything', function () {
        expect(config('bloodhound.announce_log.retention_days'))->toBeNull();
    });
});
