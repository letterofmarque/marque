<?php

declare(strict_types=1);

// Spec #98's query surface — the read-side counterpart to LogAnnounce (CP #533).
// Rows are seeded directly rather than driven through the announce route: the
// thing under test is the querying, not the write path, and seeding lets a test
// place rows at arbitrary points in time (which a real announce can't).

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Marque\Bloodhound\Contracts\AnnounceLogServiceInterface;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\AnnounceLogService;

function logRow(array $overrides = []): AnnounceLog
{
    $row = AnnounceLog::create(array_merge([
        'user_id' => 1,
        'torrent_id' => 1,
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'event' => 'regular',
        'ip' => '10.0.0.1',
        'port' => 51413,
        'user_agent' => 'qBittorrent/4.5.0',
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'upload_delta' => 0,
        'download_delta' => 0,
        'anti_cheat_flagged' => false,
        'anti_cheat_reason' => null,
    ], $overrides));

    // created_at is DB-defaulted (useCurrent) and the model is append-only, so
    // backdating a row for the $since tests has to be a direct update.
    if (isset($overrides['created_at'])) {
        AnnounceLog::whereKey($row->id)->update(['created_at' => $overrides['created_at']]);
        $row->refresh();
    }

    return $row;
}

beforeEach(function () {
    $this->service = app(AnnounceLogServiceInterface::class);
});

it('binds the interface to the implementation', function () {
    expect($this->service)->toBeInstanceOf(AnnounceLogService::class);
});

describe('forUser', function () {
    it('returns only that user\'s announces', function () {
        logRow(['user_id' => 1]);
        logRow(['user_id' => 1]);
        logRow(['user_id' => 2]);

        $result = $this->service->forUser(1);

        expect($result)->toBeInstanceOf(Collection::class)
            ->and($result)->toHaveCount(2)
            ->and($result->pluck('user_id')->unique()->all())->toBe([1]);
    });

    it('filters by $since when given', function () {
        logRow(['user_id' => 1, 'created_at' => Carbon::parse('2026-08-01 00:00:00')]);
        logRow(['user_id' => 1, 'created_at' => Carbon::parse('2026-09-01 00:00:00')]);

        $result = $this->service->forUser(1, Carbon::parse('2026-08-15 00:00:00'));

        expect($result)->toHaveCount(1)
            ->and($result->first()->created_at->toDateString())->toBe('2026-09-01');
    });

    it('returns an empty collection for a user with no announces', function () {
        logRow(['user_id' => 1]);

        expect($this->service->forUser(999))->toBeInstanceOf(Collection::class)->toBeEmpty();
    });
});

describe('forTorrent', function () {
    it('returns only that torrent\'s announces', function () {
        logRow(['torrent_id' => 5]);
        logRow(['torrent_id' => 5]);
        logRow(['torrent_id' => 6]);

        $result = $this->service->forTorrent(5);

        expect($result)->toHaveCount(2)
            ->and($result->pluck('torrent_id')->unique()->all())->toBe([5]);
    });

    it('filters by $since when given', function () {
        logRow(['torrent_id' => 5, 'created_at' => Carbon::parse('2026-08-01 00:00:00')]);
        logRow(['torrent_id' => 5, 'created_at' => Carbon::parse('2026-09-01 00:00:00')]);

        expect($this->service->forTorrent(5, Carbon::parse('2026-08-15 00:00:00')))->toHaveCount(1);
    });
});

describe('forUserAndTorrent', function () {
    it('returns only announces matching both', function () {
        logRow(['user_id' => 1, 'torrent_id' => 5]);
        logRow(['user_id' => 1, 'torrent_id' => 6]);
        logRow(['user_id' => 2, 'torrent_id' => 5]);

        $result = $this->service->forUserAndTorrent(1, 5);

        expect($result)->toHaveCount(1)
            ->and($result->first()->user_id)->toBe(1)
            ->and($result->first()->torrent_id)->toBe(5);
    });
});

describe('flagged', function () {
    it('returns only flagged announces', function () {
        logRow(['anti_cheat_flagged' => true, 'anti_cheat_reason' => 'upload speed exceeded']);
        logRow(['anti_cheat_flagged' => false]);
        logRow(['anti_cheat_flagged' => false]);

        $result = $this->service->flagged();

        expect($result)->toHaveCount(1)
            ->and($result->first()->anti_cheat_reason)->toBe('upload speed exceeded');
    });

    it('filters by $since when given', function () {
        logRow(['anti_cheat_flagged' => true, 'created_at' => Carbon::parse('2026-08-01 00:00:00')]);
        logRow(['anti_cheat_flagged' => true, 'created_at' => Carbon::parse('2026-09-01 00:00:00')]);

        expect($this->service->flagged(Carbon::parse('2026-08-15 00:00:00')))->toHaveCount(1);
    });

    it('returns an empty collection when nothing is flagged', function () {
        logRow(['anti_cheat_flagged' => false]);

        expect($this->service->flagged())->toBeEmpty();
    });
});

describe('byIp', function () {
    it('returns only announces from that IP', function () {
        logRow(['ip' => '10.0.0.1']);
        logRow(['ip' => '10.0.0.1']);
        logRow(['ip' => '10.0.0.2']);

        $result = $this->service->byIp('10.0.0.1');

        expect($result)->toHaveCount(2)
            ->and($result->pluck('ip')->unique()->all())->toBe(['10.0.0.1']);
    });

    it('handles IPv6 addresses', function () {
        logRow(['ip' => '2001:db8::1']);
        logRow(['ip' => '10.0.0.1']);

        expect($this->service->byIp('2001:db8::1'))->toHaveCount(1);
    });

    it('filters by $since when given', function () {
        logRow(['ip' => '10.0.0.1', 'created_at' => Carbon::parse('2026-08-01 00:00:00')]);
        logRow(['ip' => '10.0.0.1', 'created_at' => Carbon::parse('2026-09-01 00:00:00')]);

        expect($this->service->byIp('10.0.0.1', Carbon::parse('2026-08-15 00:00:00')))->toHaveCount(1);
    });
});

describe('ordering', function () {
    // Investigation reads newest-first — you're looking at what a user just did,
    // not paging forward from their first-ever announce.
    it('returns newest first', function () {
        logRow(['user_id' => 1, 'ip' => '10.0.0.1', 'created_at' => Carbon::parse('2026-08-01 00:00:00')]);
        logRow(['user_id' => 1, 'ip' => '10.0.0.2', 'created_at' => Carbon::parse('2026-09-01 00:00:00')]);

        expect($this->service->forUser(1)->pluck('ip')->all())->toBe(['10.0.0.2', '10.0.0.1']);
    });
});
