<?php

declare(strict_types=1);

// Job #109 step 1: several announce URLs served at once.
//
// A migrating tracker does not cut over. .torrent files issued years ago, on
// strangers' disks, announce to the old URL; files issued tomorrow should use
// the native one; both announce at the same time for as long as the old files
// are seeded. So every configured endpoint is live together. See
// EndpointsTestCase for the three configured here.

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Models\Torrent;

const NATIVE_KEY = 'aaaabbbbccccddddeeeeffffgggghhhh';
const LEGACY_KEY = '0123456789abcdef0123456789abcdef';

beforeEach(function () {
    // Peer state from an earlier run would read as announcing too often.
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

    $uploader = TestUser::create(['name' => 'Uploader', 'email' => 'uploader@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create([
        'name' => 'Endpoints', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => $uploader->id,
    ]);
});

function endpointAnnounce($test, string $url, string $peerId): array
{
    $query = http_build_query([
        'info_hash' => hex2bin($test->torrent->info_hash),
        'peer_id' => $peerId,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
    ]);

    $separator = str_contains($url, '?') ? '&' : '?';
    $response = trackerRequest($test, $url.$separator.$query);

    expect($response->getStatusCode())->toBe(200);

    return decodeTracker($response);
}

describe('every endpoint serves at once', function () {
    it('announces through the native and the legacy endpoint on the same install', function () {
        makeTrackerUser(NATIVE_KEY, 'native@example.com');
        makeTrackerUser(LEGACY_KEY, 'legacy@example.com');

        $native = endpointAnnounce($this, '/announce/'.NATIVE_KEY, '-qB4210-nativenative');
        $legacy = endpointAnnounce($this, '/announce.php?passkey='.LEGACY_KEY, '-qB4210-legacylegacy');

        expect($native)->not->toHaveKey('failure reason')
            ->and($native)->toHaveKey('interval')
            ->and($legacy)->not->toHaveKey('failure reason')
            ->and($legacy)->toHaveKey('interval');

        // One swarm: the second announce, through the other endpoint, counts
        // one seeder more than the first did.
        expect($legacy['complete'])->toBe($native['complete'] + 1);
    });

    it('announces through an additional path-key endpoint', function () {
        makeTrackerUser(LEGACY_KEY, 'old@example.com');

        expect(endpointAnnounce($this, '/tracker/announce/'.LEGACY_KEY, '-qB4210-oldoldoldold'))
            ->not->toHaveKey('failure reason');
    });

    it('scrapes through each endpoint', function () {
        $hash = urlencode(hex2bin($this->torrent->info_hash));

        foreach (['/scrape?info_hash='.$hash, '/scrape.php?info_hash='.$hash, '/tracker/scrape?info_hash='.$hash] as $url) {
            expect(decodeTracker(trackerRequest($this, $url)))->toHaveKey('files');
        }
    });
});

describe('each endpoint keeps its own shape', function () {
    // The legacy endpoint takes 32 hex characters; the native one, 32
    // alphanumerics. Each is vetted against its own pattern, never another's.
    it('vets a key against its own endpoint\'s pattern', function () {
        makeTrackerUser(NATIVE_KEY, 'native@example.com');

        $response = trackerRequest($this, '/announce.php?passkey='.NATIVE_KEY);

        expect(decodeTracker($response)['failure reason'])->toBe('Invalid announce key');
    });

    it('reads the key where its own endpoint puts it', function () {
        expect(trackerRequest($this, '/announce.php/'.LEGACY_KEY)->getStatusCode())->toBe(404);
    });

    it('answers a malformed key on an additional path-key endpoint with a bencoded failure, not a 404', function (string $url) {
        $response = trackerRequest($this, $url);

        expect($response->getStatusCode())->toBe(200)
            ->and(decodeTracker($response)['failure reason'])->toBe('Invalid announce key');
    })->with([
        'malformed' => '/tracker/announce/junk',
        'missing' => '/tracker/announce',
        'malformed scrape key' => '/tracker/scrape/junk',
    ]);
});

describe('names and downloads', function () {
    it('keeps the primary endpoint\'s route names, and names the others after themselves', function () {
        expect(Route::has('tracker.announce'))->toBeTrue()
            ->and(Route::has('tracker.scrape'))->toBeTrue()
            ->and(Route::getRoutes()->getByName('tracker.announce.legacy')?->uri())->toBe('announce.php')
            ->and(Route::getRoutes()->getByName('tracker.scrape.legacy')?->uri())->toBe('scrape.php')
            ->and(Route::getRoutes()->getByName('tracker.announce.old')?->uri())->toBe('tracker/announce/{passkey}');
    });

    // New .torrent files carry the native URL; only the old ones on
    // strangers' disks still point at the legacy one.
    it('gives downloads the primary endpoint\'s URL', function () {
        $user = makeTrackerUser(NATIVE_KEY, 'download@example.com');

        expect(app(TorrentFilePolicyInterface::class)->announceUrlFor($user))->toBe(url('/announce/'.NATIVE_KEY));
    });
});
