<?php

declare(strict_types=1);

// Job #193 (design section 3, addendum A5): Redis holds the baseline every
// announce is credited against, so it must keep what it holds. bloodhound
// checks that it does (AOF on, noeviction), refuses announces in production
// when it does not, and, if Redis is lost anyway, recovers baselines from the
// ledger for a while instead of crediting nothing.

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\RedisDurability;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;

/**
 * A durability check whose INFO answers are set by the test. Null for a
 * section means the server refused INFO, as some managed proxies do.
 *
 * @param  array<string, string>|null  $persistence
 * @param  array<string, string>|null  $memory
 */
function durabilityWith(?array $persistence, ?array $memory): RedisDurability
{
    $durability = new class extends RedisDurability
    {
        public ?array $persistence = null;

        public ?array $memory = null;

        protected function info(string $section): array
        {
            $answer = $section === 'persistence' ? $this->persistence : $this->memory;

            return $answer ?? throw new RuntimeException('INFO is not permitted');
        }
    };

    $durability->persistence = $persistence;
    $durability->memory = $memory;
    app()->instance(RedisDurability::class, $durability);

    return $durability;
}

function durableAnnounce($test, TestUser $user, Torrent $torrent, array $params = [])
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->get('/announce/'.keyOf($user).'?'.http_build_query(array_merge([
            'info_hash' => hex2bin($torrent->info_hash), 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'compact' => 1,
        ], $params)));
}

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => false]);

    $this->user = TestUser::create(['name' => 'D', 'email' => 'durable@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'durabledurabledurabledurable0000');
    $this->torrent = Torrent::create(['name' => 'D', 'info_hash' => str_repeat('8', 40), 'size' => 1_000, 'user_id' => $this->user->id]);
});

describe('the verdict', function () {
    test('AOF on and noeviction is durable', function () {
        expect(durabilityWith(['aof_enabled' => '1'], ['maxmemory_policy' => 'noeviction'])->verify()->ok())->toBeTrue();
    });

    test('AOF off is not', function () {
        $verdict = durabilityWith(['aof_enabled' => '0'], ['maxmemory_policy' => 'noeviction'])->verify();

        expect($verdict->ok())->toBeFalse()
            ->and(implode(' ', $verdict->problems))->toContain('appendonly yes');
    });

    test('an evicting policy is not, even when persistence is attested', function () {
        config(['bloodhound.redis.durability' => 'attested']);

        $verdict = durabilityWith(['aof_enabled' => '0'], ['maxmemory_policy' => 'allkeys-lru'])->verify();

        expect($verdict->ok())->toBeFalse()
            ->and(implode(' ', $verdict->problems))->toContain('noeviction');
    });

    test('attestation covers persistence a provider gives another way', function () {
        config(['bloodhound.redis.durability' => 'attested']);

        expect(durabilityWith(['aof_enabled' => '0'], ['maxmemory_policy' => 'noeviction'])->verify()->ok())->toBeTrue();
    });

    test('where INFO is refused, only attestation passes', function () {
        expect(durabilityWith(null, null)->verify()->ok())->toBeFalse();

        config(['bloodhound.redis.durability' => 'attested']);

        expect(durabilityWith(null, null)->verify()->ok())->toBeTrue();
    });
});

describe('bloodhound:check-redis', function () {
    test('reports what to change and exits non-zero', function () {
        durabilityWith(['aof_enabled' => '0'], ['maxmemory_policy' => 'allkeys-lru']);

        $this->artisan('bloodhound:check-redis')
            ->expectsOutputToContain('appendonly yes')
            ->expectsOutputToContain('maxmemory-policy noeviction')
            ->assertFailed();
    });

    test('passes a durable Redis', function () {
        durabilityWith(['aof_enabled' => '1'], ['maxmemory_policy' => 'noeviction']);

        $this->artisan('bloodhound:check-redis')->assertSuccessful();
    });
});

describe('when Redis is not durable', function () {
    // Back to testing before teardown: Testbench rolls the fixture migrations
    // back then, and in production a rollback asks for confirmation.
    afterEach(function () {
        $this->app['env'] = 'testing';
    });

    test('production refuses announces, writes nothing, and says why once', function () {
        Log::spy();
        $this->app['env'] = 'production';
        durabilityWith(['aof_enabled' => '0'], ['maxmemory_policy' => 'allkeys-lru']);

        // A public address: production refuses private ones before this.
        $this->withServerVariables(['REMOTE_ADDR' => '93.184.216.34']);

        durableAnnounce($this, $this->user, $this->torrent, ['event' => 'started'])->assertOk()->assertSee('not configured for durability');
        durableAnnounce($this, $this->user, $this->torrent)->assertOk()->assertSee('not configured for durability');

        expect(AnnounceLog::query()->count())->toBe(0);
        Log::shouldHaveReceived('critical')->once();
    });

    test('anywhere else, announces proceed and a warning is logged', function () {
        Log::spy();
        durabilityWith(['aof_enabled' => '0'], ['maxmemory_policy' => 'allkeys-lru']);

        durableAnnounce($this, $this->user, $this->torrent, ['event' => 'started'])->assertOk()->assertDontSee('failure reason');

        expect(AnnounceLog::query()->count())->toBe(1);
        Log::shouldHaveReceived('warning')->once();
    });

    test('the scheduled aggregation says so every run in production, whether or not anyone announces', function () {
        Log::spy();
        $this->app['env'] = 'production';
        durabilityWith(['aof_enabled' => '0'], ['maxmemory_policy' => 'noeviction']);

        $this->artisan('bloodhound:aggregate-ledger')->assertSuccessful();

        Log::shouldHaveReceived('critical')->once();
    });
});

describe('recovery mode (design 5.7)', function () {
    // With Redis trusted to keep baselines, the ledger is read for one only
    // when Redis has actually been lost: its sentinel gone while the ledger
    // shows announces in the last peer_expiry.
    test('after Redis is lost, the next announce is credited from the ledger', function () {
        durableAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'uploaded' => 1_000])->assertOk();

        Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

        durableAnnounce($this, $this->user, $this->torrent, ['uploaded' => 4_000])->assertOk();

        expect(AnnounceLog::query()->orderByDesc('id')->value('upload_delta'))->toBe(3_000)
            ->and(app(RedisDurability::class)->recovering())->toBeTrue();
    });

    test('with Redis intact, a peer Redis does not know is new: the ledger is not read', function () {
        durableAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'uploaded' => 1_000])->assertOk();

        // The peer's record alone is gone (as after baseline retention); the
        // rest of Redis, sentinel included, is intact.
        Redis::connection(config('threepio.redis.connection', 'default'))
            ->hdel(config('threepio.redis.prefix').'peers:'.$this->torrent->id, '-qB4210-aaaaaaaaaaaa');

        durableAnnounce($this, $this->user, $this->torrent, ['uploaded' => 4_000])->assertOk();

        expect(AnnounceLog::query()->orderByDesc('id')->value('prior_up'))->toBeNull()
            ->and(app(RedisDurability::class)->recovering())->toBeFalse();
    });

    test('a fresh install, with no recent announces, is not in recovery', function () {
        durableAnnounce($this, $this->user, $this->torrent, ['event' => 'started'])->assertOk();

        expect(app(RedisDurability::class)->recovering())->toBeFalse();
    });

    test('check-redis --recover opens the window by hand', function () {
        durabilityWith(['aof_enabled' => '1'], ['maxmemory_policy' => 'noeviction']);

        $this->artisan('bloodhound:check-redis', ['--recover' => true])->assertSuccessful();

        expect(app(RedisDurability::class)->recovering())->toBeTrue();
    });
});

test('expired peers are kept as baselines for baseline_retention', function () {
    config(['bloodhound.baseline_retention' => 86_400]);
    $this->travelTo(now()->startOfMinute());

    durableAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'uploaded' => 1_000])->assertOk();

    $this->travel(2)->hours();
    $this->artisan('bloodhound:sync-swarm-counts')->assertSuccessful();

    durableAnnounce($this, $this->user, $this->torrent, ['uploaded' => 1_500])->assertOk();

    $row = AnnounceLog::query()->orderByDesc('id')->first();

    expect($row->prior_up)->toBe(1_000)
        ->and($row->upload_delta)->toBe(500)
        ->and($row->seed_seconds)->toBe(3_600);
});
