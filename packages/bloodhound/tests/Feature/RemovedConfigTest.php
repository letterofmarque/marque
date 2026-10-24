<?php

declare(strict_types=1);

// Job #193 (design section 6): the ledger can no longer be switched off, the
// queue keys retired by Spec #99 are gone, and max_connections_per_torrent is
// replaced by location limits. A stale published config must say so rather
// than be silently ignored.

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\BloodhoundServiceProvider;
use Marque\Bloodhound\Exceptions\RemovedConfiguration;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;

function checkRemovedConfig(): void
{
    (new BloodhoundServiceProvider(app()))->refuseRemovedConfiguration();
}

// F4: with it off nothing was credited at all, while the docs called it a
// supported mode.
test('a config that switches the ledger off is refused, naming what to do', function () {
    config(['bloodhound.announce_log.enabled' => false]);

    expect(fn () => checkRemovedConfig())->toThrow(RemovedConfiguration::class, 'announce_log.enabled was removed in 8.0.0');
});

test('a published config still saying true is fine', function () {
    config(['bloodhound.announce_log.enabled' => true]);

    checkRemovedConfig();

    expect(true)->toBeTrue();
});

test('the switch and the queue keys are gone from the config', function () {
    expect(config('bloodhound.announce_log'))->not->toHaveKey('enabled')
        ->and(config('bloodhound.queue'))->toBeNull();
});

test('max_connections_per_ip is reported too, as removed', function () {
    Log::spy();
    config(['bloodhound.anti_cheat.max_connections_per_ip' => 10]);

    checkRemovedConfig();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'max_connections_per_ip') && str_contains($message, 'max_clients_per_torrent'));
});

test('max_connections_per_torrent is reported once, naming its replacements', function () {
    Log::spy();
    config(['bloodhound.anti_cheat.max_connections_per_torrent' => 3]);

    checkRemovedConfig();
    checkRemovedConfig();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'max_seeding_locations_per_torrent'));
});

test('the ledger is written whatever a stale config says', function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => false]);
    $user = TestUser::create(['name' => 'R', 'email' => 'removed@example.com', 'password' => 'p']);
    issueAnnounceKey($user, 'removedremovedremovedremoved0000');
    $torrent = Torrent::create(['name' => 'R', 'info_hash' => str_repeat('7', 40), 'size' => 1_000, 'user_id' => $user->id]);

    config(['bloodhound.announce_log.enabled' => false]);

    $this->withoutMiddleware(BlockBrowsers::class)->get('/announce/'.keyOf($user).'?'.http_build_query([
        'info_hash' => hex2bin($torrent->info_hash), 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'port' => 51413,
        'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'event' => 'started', 'compact' => 1,
    ]))->assertOk();

    expect(AnnounceLog::query()->count())->toBe(1);
});

// #11070 item 2: twentyt's published config still set min_announce_interval
// from BLOODHOUND_MIN_ANNOUNCE_INTERVAL=900. The setting moved to threepio in
// 2.0.0, so 300 had been in effect for several majors with nothing said.
describe('keys that moved to threepio', function () {
    test('are reported with their new home, env var and the value actually in effect', function () {
        Log::spy();
        config(['bloodhound.min_announce_interval' => 900, 'threepio.min_announce_interval' => 300]);

        checkRemovedConfig();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'bloodhound.min_announce_interval')
            && str_contains($message, 'threepio.min_announce_interval')
            && str_contains($message, 'THREEPIO_MIN_ANNOUNCE_INTERVAL')
            && str_contains($message, '900')
            && str_contains($message, '300'));
    });

    test('cover every protocol key bloodhound used to hold, the redis block included', function () {
        Log::spy();
        config([
            'bloodhound.announce_interval' => 1800,
            'bloodhound.min_announce_interval' => 300,
            'bloodhound.max_peers_per_announce' => 50,
            'bloodhound.peer_expiry' => 3600,
            'bloodhound.redis.connection' => 'tracker',
            'bloodhound.redis.prefix' => 'bloodhound:',
        ]);

        checkRemovedConfig();

        foreach (['announce_interval', 'min_announce_interval', 'max_peers_per_announce', 'peer_expiry', 'redis.connection', 'redis.prefix'] as $key) {
            Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, "bloodhound.{$key} ") && str_contains($message, "threepio.{$key}"));
        }
    });

    test('leave bloodhound.redis.durability alone, which is still bloodhound\'s', function () {
        Log::spy();
        config(['bloodhound.redis.durability' => 'attested']);

        checkRemovedConfig();

        Log::shouldNotHaveReceived('warning');
    });
});

test('the retired logging and queue blocks are reported as ignored', function () {
    Log::spy();
    config(['bloodhound.logging' => ['enabled' => true], 'bloodhound.queue' => ['enabled' => false]]);

    checkRemovedConfig();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'bloodhound.logging') && str_contains($message, 'announce_log'));
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'bloodhound.queue'));
});

// 9.2.0: the grace is set in GB. grace_bytes is still honoured, so it's
// reported with the number in effect, not as ignored.
test('a grace_bytes still set is reported as deprecated, with the GB it amounts to', function () {
    Log::spy();
    config(['bloodhound.enforcement.grace_bytes' => 20 * 1024 ** 3]);

    checkRemovedConfig();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'grace_bytes is deprecated')
        && str_contains($message, 'still honoured')
        && str_contains($message, '20 GB')
        && str_contains($message, 'BLOODHOUND_RATIO_GRACE_GB'));
});

test('the shipped config carries grace_gb 5 and no grace_bytes', function () {
    expect(config('bloodhound.enforcement.grace_gb'))->toBe(5)
        ->and(config('bloodhound.enforcement.grace_bytes'))->toBeNull();
});

test('the package\'s own config reports nothing', function () {
    Log::spy();

    checkRemovedConfig();

    Log::shouldNotHaveReceived('warning');
});
