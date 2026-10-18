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
