<?php

declare(strict_types=1);

// #10732 (Job #255). What a restricted member loses: new leeching. A leech
// announce on a torrent they aren't already in is refused with the reason,
// and so is the .torrent download. Seeding, and finishing what they started,
// are never refused: seeding is how they recover.

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Testing\FakeTorrent;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Enums\StandingState;
use Marque\Trove\Exceptions\DownloadRefused;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentFileService;
use Marque\Trove\Services\TorrentService;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.ratio_mode' => 'full', 'bloodhound.min_ratio' => 0.5, 'bloodhound.anti_cheat.enabled' => false]);

    $this->member = makeTrackerUser('restrictedrestrictedrestricted00', 'restricted@example.com');
    $this->member->forceFill(['uploaded' => 1024 ** 3, 'downloaded' => 20 * 1024 ** 3])->save();
    $this->torrent = Torrent::create(['name' => 'New', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => $this->member->id]);
});

function restrict(TestUser $member): void
{
    MemberStanding::query()->create(['user_id' => $member->id, 'state' => 'restricted', 'restricted_at' => now()]);
}

function announceOn(object $test, TestUser $member, Torrent $torrent, int $left, ?string $event = 'started', string $peerId = '-qB4210-rrrrrrrrrrrr'): array
{
    $query = http_build_query(array_filter([
        'info_hash' => hex2bin($torrent->info_hash),
        'peer_id' => $peerId,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => $left,
        'event' => $event,
        'compact' => 1,
    ], fn ($v) => $v !== null));

    return decodeTracker(trackerRequest($test, '/announce/'.keyOf($member).'?'.$query)->assertOk());
}

describe('at announce', function () {
    it('refuses a restricted member\'s leech on a torrent they are not in, saying why', function () {
        restrict($this->member);

        $response = announceOn($this, $this->member, $this->torrent, left: 1_000);

        expect($response['failure reason'] ?? null)->toContain('paused')->toContain('0.50')
            ->and(AnnounceLog::query()->where('anti_cheat_flagged', true)->value('anti_cheat_reason'))->toContain('paused');
    });

    it('still answers a restricted member seeding', function () {
        restrict($this->member);

        expect(announceOn($this, $this->member, $this->torrent, left: 0))->not->toHaveKey('failure reason');
    });

    it('still answers a restricted member finishing a torrent they were already in', function () {
        restrict($this->member);
        TorrentUser::query()->forceCreate(['user_id' => $this->member->id, 'torrent_id' => $this->torrent->id]);

        expect(announceOn($this, $this->member, $this->torrent, left: 1_000))->not->toHaveKey('failure reason');
    });

    it('still answers a restricted member whose peer is already in the swarm, before the ledger folds it', function () {
        announceOn($this, $this->member, $this->torrent, left: 1_000);
        restrict($this->member);

        expect(announceOn($this, $this->member, $this->torrent, left: 900, event: null))->not->toHaveKey('failure reason');
    });

    // A client restarted under a new peer ID is still the member finishing
    // what they started, even before the ledger folds a torrent_user row.
    it('still answers a restricted member\'s second client on a torrent they are in', function () {
        announceOn($this, $this->member, $this->torrent, left: 1_000, peerId: '-qB4210-aaaaaaaaaaaa');
        restrict($this->member);

        expect(announceOn($this, $this->member, $this->torrent, left: 1_000, peerId: '-qB4210-bbbbbbbbbbbb'))
            ->not->toHaveKey('failure reason');
    });

    it('still answers a restricted member stopping a leech', function () {
        restrict($this->member);

        expect(announceOn($this, $this->member, $this->torrent, left: 1_000, event: 'stopped'))->not->toHaveKey('failure reason');
    });

    it('answers a member on watch normally', function () {
        MemberStanding::query()->create(['user_id' => $this->member->id, 'state' => 'watch', 'deadline_at' => now()->addDays(3)]);

        expect(announceOn($this, $this->member, $this->torrent, left: 1_000))->not->toHaveKey('failure reason');
    });

    it('refuses nobody once enforcement is off, before the next run clears the row', function (string $mode) {
        restrict($this->member);
        config(['bloodhound.ratio_mode' => $mode]);

        expect(announceOn($this, $this->member, $this->torrent, left: 1_000))->not->toHaveKey('failure reason');
    })->with(['off', 'seedtime']);

    it('refuses nobody an admin has exempted', function () {
        MemberStanding::query()->create(['user_id' => $this->member->id, 'state' => 'restricted', 'exempted_at' => now(), 'exempt_reason' => 'Donor']);

        expect(announceOn($this, $this->member, $this->torrent, left: 1_000))->not->toHaveKey('failure reason');
    });
});

describe('the .torrent download', function () {
    beforeEach(function () {
        Storage::fake(config('trove.storage_disk', 'local'));
        $this->stored = app(TorrentService::class)->createFromUpload(FakeTorrent::make()->upload(), $this->member, 'Stored');
    });

    it('is refused for a restricted member, with the reason', function () {
        restrict($this->member);

        expect(fn () => app(TorrentFileService::class)->forDownload($this->stored, $this->member))
            ->toThrow(DownloadRefused::class, 'paused');
    });

    it('is served to a member who is not restricted', function () {
        expect(app(TorrentFileService::class)->forDownload($this->stored, $this->member))->toBeString();
    });

    it('is served for a torrent the restricted member is in, before the ledger has folded it', function () {
        announceOn($this, $this->member, $this->stored, left: 1_000);
        restrict($this->member);

        expect(TorrentUser::query()->where('torrent_id', $this->stored->id)->exists())->toBeFalse()
            ->and(app(TorrentFileService::class)->forDownload($this->stored, $this->member))->toBeString();
    });

    it('is served for a torrent the restricted member is already in, to replace a lost file', function () {
        restrict($this->member);
        TorrentUser::query()->forceCreate(['user_id' => $this->member->id, 'torrent_id' => $this->stored->id]);

        expect(app(TorrentFileService::class)->forDownload($this->stored, $this->member))->toBeString();
    });
});

describe('statsFor', function () {
    it('reports a restriction, with its reason', function () {
        restrict($this->member);

        $standing = app(TrackerStatsInterface::class)->statsFor($this->member)->standing;

        expect($standing->state)->toBe(StandingState::Restricted)
            ->and($standing->reason)->toContain('0.05');
    });

    it('reports a watch, with its deadline', function () {
        MemberStanding::query()->create(['user_id' => $this->member->id, 'state' => 'watch', 'deadline_at' => '2026-12-01 00:00:00']);

        $standing = app(TrackerStatsInterface::class)->statsFor($this->member)->standing;

        expect($standing->state)->toBe(StandingState::Watch)
            ->and($standing->until?->toDateString())->toBe('2026-12-01');
    });

    it('reports ok for a member with nothing against them', function () {
        expect(app(TrackerStatsInterface::class)->statsFor($this->member)->standing->state)->toBe(StandingState::Ok);
    });
});
