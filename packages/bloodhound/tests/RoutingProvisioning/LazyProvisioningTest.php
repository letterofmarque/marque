<?php

declare(strict_types=1);

// Job #109 step 5: resolveUser() and lazy migration.
//
// The alternative to provisioning on first announce is importing every member
// of the old tracker, 100,000 rows of whom perhaps 10,000 ever come back. So a
// key Marque doesn't know, but the old tracker does, creates its member the
// first time it announces. The old tracker is the gate: a key it never issued
// creates nothing. See ProvisioningTestCase and LegacyAccountsTransform.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Tests\LegacyAccountsTransform;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

const LEGACY = 'feedfacefeedfacefeedfacefeedface';
const NOBODY = 'deadbeefdeadbeefdeadbeefdeadbeef';

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    LegacyAccountsTransform::$legacy = [LEGACY => ['email' => 'returning@example.com', 'uploaded' => 7_000]];
    LegacyAccountsTransform::$created = 0;
    LegacyAccountsTransform::$carriedBy = null;

    $this->uploader = TestUser::create(['name' => 'Uploader', 'email' => 'uploader@example.com', 'password' => 'p', 'role' => 'admin']);
    $this->torrent = Torrent::create(['name' => 'T', 'info_hash' => str_repeat('d', 40), 'size' => 1_000, 'user_id' => $this->uploader->id]);
});

function provisioningAnnounce($test, string $url, string $peerId = '-qB4210-returningret'): array
{
    $query = http_build_query([
        'info_hash' => hex2bin($test->torrent->info_hash), 'peer_id' => $peerId, 'port' => 51413,
        'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'compact' => 1,
    ]);

    return decodeTracker(trackerRequest($test, $url.(str_contains($url, '?') ? '&' : '?').$query));
}

it('provisions a returning member on their first announce, with their old key', function () {
    $response = provisioningAnnounce($this, '/announce.php?passkey='.LEGACY);

    $user = TestUser::query()->where('email', 'returning@example.com')->sole();

    expect($response)->not->toHaveKey('failure reason')
        ->and($response)->toHaveKey('interval')
        ->and(AnnounceKey::query()->where('user_id', $user->id)->value('key'))->toBe(LEGACY);
});

it('provisions them once: the next announce finds them in Marque', function () {
    provisioningAnnounce($this, '/announce.php?passkey='.LEGACY);
    provisioningAnnounce($this, '/announce.php?passkey='.LEGACY, '-qB4210-secondsecond');

    expect(LegacyAccountsTransform::$created)->toBe(1)
        ->and(TestUser::query()->where('email', 'returning@example.com')->count())->toBe(1);
});

it('gives them the native URL too, with the same key', function () {
    provisioningAnnounce($this, '/announce.php?passkey='.LEGACY);

    expect(provisioningAnnounce($this, '/announce/'.LEGACY, '-qB4210-nativenative'))->not->toHaveKey('failure reason');
});

// The distinction the whole feature rests on: null is "not mine", never
// "so create one".
it('creates nothing for a key the old tracker never issued', function () {
    $users = TestUser::query()->count();
    $keys = AnnounceKey::query()->count();

    $response = provisioningAnnounce($this, '/announce.php?passkey='.NOBODY);

    expect($response['failure reason'])->toBe('Unknown announce key')
        ->and(TestUser::query()->count())->toBe($users)
        ->and(AnnounceKey::query()->count())->toBe($keys)
        ->and(LegacyAccountsTransform::$created)->toBe(0);
});

it('still finds an existing member by key when the transform resolves no one', function () {
    makeTrackerUser(NOBODY, 'already-here@example.com');

    expect(provisioningAnnounce($this, '/announce.php?passkey='.NOBODY))->not->toHaveKey('failure reason');
});

it('holds a provisioned member to the same checks as anyone', function () {
    provisioningAnnounce($this, '/announce.php?passkey='.LEGACY);
    TestUser::query()->where('email', 'returning@example.com')->update(['status' => 'banned']);

    expect(provisioningAnnounce($this, '/announce.php?passkey='.LEGACY, '-qB4210-bannedbanned')['failure reason'])->toBe('Account disabled');
});

// Replaces, not precedes: a resolving transform costs the same key lookup the
// native path does, and no second one.
it('looks the key up no more often than the native path does', function () {
    makeTrackerUser('aaaabbbbccccddddeeeeffffgggghhhh', 'native-count@example.com');
    provisioningAnnounce($this, '/announce.php?passkey='.LEGACY);

    $count = function (string $url, string $peer): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'announce_keys')) {
                $queries++;
            }
        });
        provisioningAnnounce($this, $url, $peer);
        DB::flushQueryLog();

        return $queries;
    };

    $native = $count('/announce/aaaabbbbccccddddeeeeffffgggghhhh', '-qB4210-countnative1');
    // Each call listens afresh, so each count stands alone.
    $resolved = $count('/announce.php?passkey='.LEGACY, '-qB4210-countresolv1');

    expect($native)->toBeGreaterThan(0)
        ->and($resolved)->toBe($native);
});

// OQ6: a member provisioned at zero on a ratio-enforcing tracker could be
// restricted by their own migration. The transform carries their old upload
// over as a ledger adjustment, on the record, and the ledger stays exact.
it('can carry a provisioned member\'s old upload over, on the record', function () {
    LegacyAccountsTransform::$carriedBy = $this->uploader->id;

    provisioningAnnounce($this, '/announce.php?passkey='.LEGACY);

    $user = TestUser::query()->where('email', 'returning@example.com')->sole();

    expect((int) DB::table($user->getTable())->where('id', $user->id)->value('uploaded'))->toBe(7_000)
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
});
