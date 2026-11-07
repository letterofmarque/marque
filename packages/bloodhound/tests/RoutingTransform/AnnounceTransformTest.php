<?php

declare(strict_types=1);

// Job #109 step 2: an endpoint's transform reshapes a legacy request into one
// the native flow accepts, before the controllers see it. See TransformTestCase:
// `legacy` lower-cases the key, `plain` is the same shape with no transform.

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Tests\LowercaseKeyTransform;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

const STORED_KEY = '0123456789abcdef0123456789abcdef';
const OLD_LINK_KEY = '0123456789ABCDEF0123456789ABCDEF';

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    LowercaseKeyTransform::$applied = [];

    $uploader = TestUser::create(['name' => 'Uploader', 'email' => 'uploader@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create(['name' => 'T', 'info_hash' => str_repeat('c', 40), 'size' => 1_000, 'user_id' => $uploader->id]);
    makeTrackerUser(STORED_KEY, 'migrated@example.com');
});

function transformAnnounce($test, string $path, string $key, string $peerId): array
{
    // info_hash and peer_id are binary, read from the raw query string, so a
    // successful announce also proves the reshaped query string kept them.
    $query = http_build_query([
        'passkey' => $key,
        'info_hash' => hex2bin($test->torrent->info_hash),
        'peer_id' => $peerId,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
    ]);

    return decodeTracker(trackerRequest($test, "/{$path}?{$query}"));
}

it('announces end to end once the transform has reshaped the request', function () {
    $first = transformAnnounce($this, 'announce.php', OLD_LINK_KEY, '-qB4210-firstfirstfi');
    $second = transformAnnounce($this, 'announce.php', OLD_LINK_KEY, '-qB4210-secondsecond');

    expect($first)->not->toHaveKey('failure reason')
        ->and($first)->toHaveKey('interval')
        // Peer state was written: the second announce counts the first.
        ->and($second['complete'])->toBe($first['complete'] + 1);
});

it('is the transform that made it work', function () {
    expect(transformAnnounce($this, 'announce2.php', OLD_LINK_KEY, '-qB4210-plainplainpl')['failure reason'])
        ->toBe('Unknown announce key');
});

// OQ1: a legacy scrape URL has the same shape as its announce URL, so the
// endpoint's one transform reshapes both.
it('reshapes scrape with the same transform', function () {
    $response = trackerRequest($this, '/scrape.php?passkey='.OLD_LINK_KEY.'&info_hash='.urlencode(hex2bin($this->torrent->info_hash)));

    expect(decodeTracker($response))->toHaveKey('files')
        ->and(LowercaseKeyTransform::$applied)->toBe(['scrape.php']);
});

it('leaves an endpoint without a transform alone', function () {
    transformAnnounce($this, 'announce2.php', STORED_KEY, '-qB4210-plainplainpl');

    expect(LowercaseKeyTransform::$applied)->toBe([]);
});

it('resolves the transform from the container', function () {
    $bound = new class extends LowercaseKeyTransform
    {
        public static bool $used = false;

        public function apply(Request $request): Request
        {
            self::$used = true;

            return parent::apply($request);
        }
    };
    app()->instance(LowercaseKeyTransform::class, $bound);

    transformAnnounce($this, 'announce.php', OLD_LINK_KEY, '-qB4210-boundboundbo');

    expect($bound::$used)->toBeTrue();
});
