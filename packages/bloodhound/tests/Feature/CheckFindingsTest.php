<?php

declare(strict_types=1);

// The independent check of Job #193 found these with the full suite green.
// Each test is that check's reproduction.

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\BloodhoundServiceProvider;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\RedisDurability;
use Marque\Bloodhound\Services\TimeOpeningBalance;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => true, 'bloodhound.anti_cheat.min_announce_gap' => 0]);

    $this->user = TestUser::create(['name' => 'A', 'email' => 'check-a@example.com', 'password' => 'p']);
    $this->other = TestUser::create(['name' => 'B', 'email' => 'check-b@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'checkacheckacheckacheckacheck000');
    issueAnnounceKey($this->other, 'checkbcheckbcheckbcheckbcheck000');
    $this->torrent = Torrent::create(['name' => 'C', 'info_hash' => str_repeat('5', 40), 'size' => 1_000_000, 'user_id' => $this->user->id]);

    $this->t = Carbon::parse('2026-10-18 10:00:00');
});

function found($test, int $seconds, array $params = [], ?TestUser $user = null, string $ip = '10.0.0.1'): string
{
    $test->travelTo($test->t->copy()->addSeconds($seconds));

    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/announce/'.keyOf($user ?? $test->user).'?'.http_build_query(array_merge([
            'info_hash' => hex2bin($test->torrent->info_hash), 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'compact' => 1,
        ], $params)))->assertOk()->getContent();
}

function totalsOf(TestUser $user): array
{
    $row = DB::table($user->getTable())->where('id', $user->id)->first();

    return [(int) $row->uploaded, (int) $row->seedtime];
}

// Bug 1 (high): a stop credited against a retained baseline left it in place.
test('a retained baseline is credited once, however many stops and restarts follow', function () {
    found($this, 0, ['event' => 'started']);
    found($this, 1_800, ['uploaded' => 1_000]);

    $this->travelTo($this->t->copy()->addSeconds(5_800));
    app(PeerService::class)->cleanupExpiredPeers($this->torrent->id);

    found($this, 5_800, ['event' => 'stopped', 'uploaded' => 2_000]);
    found($this, 5_801, ['event' => 'stopped', 'uploaded' => 2_000]);
    found($this, 5_802, ['event' => 'started']);
    app(LedgerAggregator::class)->run();

    // 1,800 s to the first regular, the capped 3,600 s to the stop; 2,000 bytes.
    expect(totalsOf($this->user))->toBe([2_000, 5_400])
        ->and(app(LedgerAuditor::class)->audit()->hasBreaks())->toBeFalse();
});

// Bug 4: another member's announce under your peer_id erased your record.
test('a peer_id live for another member is refused, and your baseline survives', function () {
    found($this, 0, ['event' => 'started']);
    found($this, 60, ['uploaded' => 1_000]);

    $stolen = found($this, 120, ['event' => 'stopped'], user: $this->other, ip: '10.9.9.9');

    found($this, 180, ['uploaded' => 5_000]);
    app(LedgerAggregator::class)->run();

    expect($stolen)->toContain('in use')
        ->and(totalsOf($this->user)[0])->toBe(5_000)
        ->and(totalsOf($this->other)[0])->toBe(0);
});

// Bug 3: a client started as a seeder, then leeching, skipped the leeching limit.
test('turning from seeding to leeching at a new location is held to the leeching limit', function () {
    found($this, 0, ['event' => 'started', 'left' => 500, 'peer_id' => '-qB4210-111111111111'], ip: '10.0.0.1');
    found($this, 10, ['event' => 'started', 'left' => 0, 'peer_id' => '-qB4210-222222222222'], ip: '10.0.0.3');

    expect(found($this, 70, ['left' => 500, 'peer_id' => '-qB4210-222222222222'], ip: '10.0.0.3'))->toContain('leeching locations');
});

test('a live peer_id announcing from a new location is held to the limits there', function () {
    config(['bloodhound.anti_cheat.max_seeding_locations_per_torrent' => 1]);
    found($this, 0, ['event' => 'started', 'peer_id' => '-qB4210-111111111111'], ip: '10.0.0.1');
    found($this, 10, ['event' => 'started', 'peer_id' => '-qB4210-222222222222'], ip: '10.0.0.1');

    expect(found($this, 70, ['peer_id' => '-qB4210-222222222222'], ip: '10.0.0.2'))->toContain('seeding locations');
});

test('a seeder at its own location carries on, and a leecher finishing is never refused', function () {
    config(['bloodhound.anti_cheat.max_seeding_locations_per_torrent' => 1]);
    found($this, 0, ['event' => 'started', 'left' => 500], ip: '10.0.0.1');

    expect(found($this, 60, ['event' => 'completed', 'left' => 0, 'downloaded' => 500], ip: '10.0.0.1'))->not->toContain('failure')
        ->and(found($this, 120, [], ip: '10.0.0.1'))->not->toContain('failure');
});

// Bug 5a: three protocol events inside one second read as a duplicate interval.
test('started, completed and stopped in one second audit clean', function () {
    found($this, 0, ['event' => 'started', 'left' => 500]);
    found($this, 0, ['event' => 'completed', 'left' => 0, 'downloaded' => 500]);
    found($this, 0, ['event' => 'stopped', 'left' => 0, 'downloaded' => 500]);

    expect(AnnounceLog::query()->count())->toBe(3)
        ->and(app(LedgerAuditor::class)->audit()->chainBreaks)->toBe([]);
});

// Bug 5 (check item 6a): the durability verdict and the removed-config notice
// went through the cache, so a cache outage failed every announce.
test('announces still work when the cache store is down', function () {
    Cache::extend('broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('cache down');
        }

        public function add($key, $value, $seconds)
        {
            throw new RuntimeException('cache down');
        }
    }));
    config(['cache.stores.broken' => ['driver' => 'broken'], 'cache.default' => 'broken', 'bloodhound.anti_cheat.max_connections_per_torrent' => 3]);
    app('cache')->forgetDriver('broken');

    (new BloodhoundServiceProvider(app()))->refuseRemovedConfiguration();

    expect(found($this, 0, ['event' => 'started']))->not->toContain('failure');
});

// Check items 6b and 6c: a prune, and the time opening-balance migration,
// write carry; both take the cursor lock first, as a rebuild does, so neither
// interleaves with a rebuild or a fold.
test('a prune locks the cursor before it deletes anything', function () {
    config(['bloodhound.announce_log.retention_days' => 1]);
    $row = AnnounceLog::create([
        'user_id' => $this->user->id, 'torrent_id' => 1, 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'event' => 'regular',
        'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => 10, 'downloaded' => 0, 'left' => 0, 'upload_delta' => 10, 'download_delta' => 0,
    ]);
    $row->forceFill(['created_at' => now()->subDays(3)])->save();
    app(LedgerAggregator::class)->run();

    DB::enableQueryLog();
    $this->artisan('bloodhound:prune-announce-log')->assertSuccessful();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $lock = $queries->search(fn ($sql) => str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'ledger_cursors'));
    $delete = $queries->search(fn ($sql) => str_starts_with(strtolower($sql), 'delete') && str_contains($sql, 'announce_log'));

    expect($lock)->not->toBeFalse()->and($delete)->not->toBeFalse()->and($lock)->toBeLessThan($delete);
});

test('the time opening balance locks the cursor before it reads what to carry', function () {
    DB::table($this->user->getTable())->where('id', $this->user->id)->update(['seedtime' => 900]);

    DB::enableQueryLog();
    app(TimeOpeningBalance::class)->record();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $lock = $queries->search(fn ($sql) => str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'ledger_cursors'));
    $read = $queries->search(fn ($sql) => str_contains($sql, 'from') && str_contains($sql, 'announce_log'));

    expect($lock)->not->toBeFalse()->and($lock)->toBeLessThan($read);
});

// Second check, D1: two announces from one peer at once were both credited
// against the same baseline. libtorrent announces over IPv4 and IPv6 at the
// same moment with one peer ID.
test('two announces from one peer at once are credited once', function () {
    found($this, 0, ['event' => 'started']);

    $this->travelTo($this->t->copy()->addSeconds(1_800));
    $nested = false;
    AnnounceLog::creating(function () use (&$nested) {
        if ($nested) {
            return;
        }
        $nested = true;
        // The second announce arrives while the first is between reading the
        // peer and writing it back.
        $this->withoutMiddleware(BlockBrowsers::class)->withServerVariables(['REMOTE_ADDR' => '2001:db8::5'])
            ->get('/announce/'.keyOf($this->user).'?'.http_build_query([
                'info_hash' => hex2bin($this->torrent->info_hash), 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'port' => 51413,
                'uploaded' => 1_000, 'downloaded' => 0, 'left' => 0, 'compact' => 1,
            ]));
    });

    found($this, 1_800, ['uploaded' => 1_000]);
    app(LedgerAggregator::class)->run();

    expect(totalsOf($this->user))->toBe([1_000, 1_800]);
});

// Second check, D3: a dual-stack client announces over both families; the
// second, seconds later from its other address, was refused by the gap.
test('a dual-stack client\'s second announce from its other address is accepted, and credits only what is new', function () {
    config(['bloodhound.anti_cheat.min_announce_gap' => 60]);
    found($this, 0, ['event' => 'started']);
    found($this, 1_800, ['uploaded' => 1_000], ip: '203.0.113.5');

    expect(found($this, 1_802, ['uploaded' => 1_000], ip: '2001:db8::5'))->not->toContain('failure')
        ->and(found($this, 1_810, ['uploaded' => 1_000], ip: '2001:db8::5'))->toContain('too frequently');

    app(LedgerAggregator::class)->run();

    expect(totalsOf($this->user))->toBe([1_000, 1_802]);
});

// Second check, D4: the cache-outage fallback missed the seedbox lookup, which
// only an announce with a baseline reaches.
test('a regular announce works when the cache store is down', function () {
    found($this, 0, ['event' => 'started']);

    Cache::extend('broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('cache down');
        }
    }));
    config(['cache.stores.broken' => ['driver' => 'broken'], 'cache.default' => 'broken']);
    app('cache')->forgetDriver('broken');

    expect(found($this, 1_800, ['uploaded' => 1_000]))->not->toContain('failure');
});

// Second check, D5: one INFO call that failed in transit refused every
// announce for five minutes on a correctly configured Redis.
test('a failed durability check is retried soon, not held for five minutes', function () {
    $this->app['env'] = 'production';
    $durability = new class extends RedisDurability
    {
        public int $calls = 0;

        protected function info(string $section): array
        {
            if ($this->calls++ < 2) {
                throw new RuntimeException('connection reset');
            }

            return ['aof_enabled' => '1', 'maxmemory_policy' => 'noeviction'];
        }
    };

    expect($durability->allowsAnnounces())->toBeFalse();

    $this->travel(20)->seconds();

    expect($durability->allowsAnnounces())->toBeTrue();
    $this->app['env'] = 'testing';
});
