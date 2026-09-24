<?php

declare(strict_types=1);

// Build #113 CP2 (Spec #119): bloodhound implements the tracker stats contract
// trove declares. Consumers ask this instead of probing the User model.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\TrackerStatsService;
use Marque\Bloodhound\Support\AnnounceRouting;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\TorrentStats;
use Marque\Trove\Support\TrackerStats;

beforeEach(function () {
    $this->user = makeTrackerUser('aaaabbbbccccddddeeeeffffgggghhhh', 'stats@example.com');
    $this->user->forceFill(['uploaded' => 6_000, 'downloaded' => 2_000, 'seedtime' => 7_200])->save();

    $this->torrent = Torrent::create([
        'name' => 'Stats Torrent',
        'info_hash' => str_repeat('b', 40),
        'size' => 1_000_000,
        'user_id' => $this->user->id,
    ]);

    $this->stats = app(TrackerStatsInterface::class);
});

it('binds the contract to bloodhound\'s implementation', function () {
    expect(app()->bound(TrackerStatsInterface::class))->toBeTrue()
        ->and($this->stats)->toBeInstanceOf(TrackerStatsService::class);
});

describe('statsFor', function () {
    it('returns the figures stored for the user', function () {
        $stats = $this->stats->statsFor($this->user);

        expect($stats)->toBeInstanceOf(TrackerStats::class)
            ->and($stats->uploaded)->toBe(6_000)
            ->and($stats->downloaded)->toBe(2_000)
            ->and($stats->seedtime)->toBe(7_200)
            ->and($stats->ratio)->toBe(3.0);
    });

    // A new account has done nothing; that is a real state with real (zero)
    // figures, not an absence. Null is reserved for "no tracker at all".
    it('returns zeroes, not null, for a user with no traffic yet', function () {
        $fresh = makeTrackerUser('zzzzyyyyxxxxwwwwvvvvuuuuttttssss', 'fresh@example.com');

        $stats = $this->stats->statsFor($fresh);

        expect($stats)->not->toBeNull()
            ->and($stats->uploaded)->toBe(0)
            ->and($stats->downloaded)->toBe(0)
            ->and($stats->hasInfiniteRatio())->toBeTrue();
    });

    // The figures come from storage, not from whatever instance the caller
    // happens to hold — a model loaded at the start of a request must not
    // report numbers the ledger has since moved past.
    it('reads current figures even from a stale model instance', function () {
        $stale = TestUser::find($this->user->id);

        TestUser::whereKey($this->user->id)->update(['uploaded' => 9_000]);

        expect($this->stats->statsFor($stale)->uploaded)->toBe(9_000);
    });
});

describe('statsForTorrent', function () {
    it('returns the user\'s row for that torrent, completion history included', function () {
        $first = Carbon::parse('2026-03-01 10:00:00');
        $last = Carbon::parse('2026-08-01 10:00:00');

        TorrentUser::create([
            'user_id' => $this->user->id,
            'torrent_id' => $this->torrent->id,
            'uploaded' => 500,
            'downloaded' => 1_000,
            'seedtime' => 3_600,
            'first_completed_at' => $first,
            'last_completed_at' => $last,
            'times_completed' => 2,
        ]);

        $stats = $this->stats->statsForTorrent($this->user, $this->torrent);

        expect($stats)->toBeInstanceOf(TorrentStats::class)
            ->and($stats->uploaded)->toBe(500)
            ->and($stats->downloaded)->toBe(1_000)
            ->and($stats->seedtime)->toBe(3_600)
            ->and($stats->timesCompleted)->toBe(2)
            ->and($stats->ratio)->toBe(0.5)
            ->and($stats->firstCompletedAt?->format('Y-m-d H:i:s'))->toBe('2026-03-01 10:00:00')
            ->and($stats->lastCompletedAt?->format('Y-m-d H:i:s'))->toBe('2026-08-01 10:00:00');
    });

    it('returns null when the user has never announced that torrent', function () {
        expect($this->stats->statsForTorrent($this->user, $this->torrent))->toBeNull();
    });

    // Scoped to the exact pair: another user's row on the same torrent is not
    // this user's history.
    it('does not return another user\'s row for the same torrent', function () {
        $other = makeTrackerUser('11112222333344445555666677778888', 'other@example.com');
        TorrentUser::create(['user_id' => $other->id, 'torrent_id' => $this->torrent->id, 'uploaded' => 42]);

        expect($this->stats->statsForTorrent($this->user, $this->torrent))->toBeNull();
    });
});

describe('announce keys', function () {
    it('returns the user\'s current announce key', function () {
        expect($this->stats->announceKeyFor($this->user))->toBe('aaaabbbbccccddddeeeeffffgggghhhh');
    });

    it('returns null for a user with no announce key', function () {
        AnnounceKey::where('user_id', $this->user->id)->delete();

        expect($this->stats->announceKeyFor($this->user))->toBeNull();
    });

    it('regenerates and persists a new key', function () {
        $new = $this->stats->regenerateAnnounceKey($this->user);

        expect($new)->not->toBe('aaaabbbbccccddddeeeeffffgggghhhh')
            ->and(AnnounceKey::where('user_id', $this->user->id)->value('key'))->toBe($new)
            ->and($this->stats->announceKeyFor($this->user))->toBe($new);
    });

    // A user with no key yet gets one: regenerate is also how a key is issued.
    it('issues a key to a user who has none', function () {
        AnnounceKey::where('user_id', $this->user->id)->delete();

        $new = $this->stats->regenerateAnnounceKey($this->user);

        expect($this->stats->announceKeyFor($this->user))->toBe($new);
    });

    // The deprecated column is written by nothing, including this.
    it('leaves users.announce_key alone', function () {
        TestUser::whereKey($this->user->id)->update(['announce_key' => 'deadcolumndeadcolumndeadcolumn00']);

        $this->stats->regenerateAnnounceKey($this->user);

        expect(TestUser::find($this->user->id)->announce_key)->toBe('deadcolumndeadcolumndeadcolumn00');
    });

    // A minted key the router then refuses would lock the user out of their
    // own tracker the moment they pressed "regenerate".
    it('mints a key the announce route accepts', function () {
        $new = $this->stats->regenerateAnnounceKey($this->user);

        expect(AnnounceRouting::matches($new))->toBeTrue();
    });

    // Both halves, so this cannot pass merely because announcing is broken:
    // the same request succeeds with the new key and fails with the old one.
    it('moves announcing from the old key to the new one', function () {
        // Peer state lives in Redis and outlives RefreshDatabase; without this
        // a previous run's announce trips the rate limit instead.
        Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

        $new = $this->stats->regenerateAnnounceKey($this->user);

        $announce = fn (string $key, string $peer) => decodeTracker(trackerRequest($this, "/announce/{$key}?".http_build_query([
            'info_hash' => hex2bin(str_repeat('b', 40)),
            'peer_id' => $peer,
            'port' => 51413,
            'uploaded' => 0,
            'downloaded' => 0,
            'left' => 0,
        ])));

        expect($announce($new, '-qB4500-newkeypeer01'))->not->toHaveKey('failure reason')
            ->and($announce('aaaabbbbccccddddeeeeffffgggghhhh', '-qB4500-oldkeypeer01')['failure reason'] ?? null)->toBe('Unknown announce key');
    });
});
