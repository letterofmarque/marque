<?php

declare(strict_types=1);

// Job #256 step 5: bloodhound answers trove's SeedingObligationsInterface, so
// a frontend can show a member what they owe seeding on, and their strikes.

use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\HitAndRunStrike;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\HitAndRunEnforcer;
use Marque\Trove\Contracts\SeedingObligationsInterface;
use Marque\Trove\Enums\ObligationState;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config([
        'bloodhound.ratio_mode' => 'full',
        'bloodhound.min_seedtime' => 72 * 3600,
        'bloodhound.hit_and_run' => [
            'enabled' => true, 'ratio' => 1.0, 'buffer_percent' => 10,
            'prewarn_days' => 1, 'grace_days' => 3, 'expire_days' => 14, 'max_strikes' => 3,
        ],
    ]);
    $this->travelTo('2026-11-01 00:00:00');
    app(HitAndRunEnforcer::class)->since();
    $this->travelTo('2026-11-02 00:00:00');

    $this->member = makeTrackerUser('obligobligobligobligobligobligob', 'oblig@example.com');
});

function owedTorrent(object $test, string $name, int $seedtime, int $downloaded = 1_000, int $uploaded = 0): Torrent
{
    $torrent = Torrent::create(['name' => $name, 'info_hash' => str_pad(dechex(crc32($name)), 40, '0'), 'size' => 1_000, 'user_id' => $test->member->id]);
    TorrentUser::query()->forceCreate([
        'user_id' => $test->member->id, 'torrent_id' => $torrent->id,
        'uploaded' => $uploaded, 'downloaded' => $downloaded, 'seedtime' => $seedtime, 'leechtime' => 0, 'times_completed' => 1,
    ]);

    return $torrent;
}

function obligationsOf(object $test): array
{
    return app(SeedingObligationsInterface::class)->obligationsFor($test->member);
}

it('lists what the member still owes, with progress', function () {
    owedTorrent($this, 'Half Seeded', seedtime: 31 * 3600);

    $list = obligationsOf($this);

    expect($list)->toHaveCount(1)
        ->and($list[0]->torrentName)->toBe('Half Seeded')
        ->and($list[0]->seededSeconds)->toBe(31 * 3600)
        ->and($list[0]->requiredSeconds)->toBe(72 * 3600)
        ->and($list[0]->requiredRatio)->toBe(1.0)
        ->and($list[0]->state)->toBe(ObligationState::Open);
});

it('leaves out what is met, or under the buffer', function () {
    owedTorrent($this, 'Seeded Enough', seedtime: 72 * 3600);
    owedTorrent($this, 'Gave It Back', seedtime: 0, uploaded: 1_000);
    owedTorrent($this, 'Barely Touched', seedtime: 0, downloaded: 100);

    expect(obligationsOf($this))->toBe([]);
});

it('puts strikes first, with when they lapse, then pre-warnings, then the rest', function () {
    $open = owedTorrent($this, 'Open', seedtime: 0);
    $warned = owedTorrent($this, 'Warned', seedtime: 0);
    $struck = owedTorrent($this, 'Struck', seedtime: 0);
    HitAndRunStrike::query()->create(['user_id' => $this->member->id, 'torrent_id' => $warned->id, 'prewarned_at' => now()]);
    HitAndRunStrike::query()->create(['user_id' => $this->member->id, 'torrent_id' => $struck->id, 'prewarned_at' => now(), 'struck_at' => now(), 'expires_at' => '2026-11-16 00:00:00']);

    $list = obligationsOf($this);

    expect(array_map(fn ($o) => $o->torrentName, $list))->toBe(['Struck', 'Warned', 'Open'])
        ->and($list[0]->state)->toBe(ObligationState::Struck)
        ->and($list[0]->until?->toDateString())->toBe('2026-11-16')
        ->and($list[1]->state)->toBe(ObligationState::Prewarned);
});

it('leaves out a torrent whose strike lapsed or was cleared', function () {
    $lapsed = owedTorrent($this, 'Lapsed', seedtime: 0);
    HitAndRunStrike::query()->create(['user_id' => $this->member->id, 'torrent_id' => $lapsed->id, 'prewarned_at' => now()->subDays(20), 'struck_at' => now()->subDays(18), 'expires_at' => now()->subDays(4)]);

    expect(obligationsOf($this))->toBe([]);
});

it('is empty when hit-and-run is off, or for an exempt member', function () {
    owedTorrent($this, 'Owed', seedtime: 0);

    config(['bloodhound.hit_and_run.enabled' => false]);
    expect(obligationsOf($this))->toBe([]);

    config(['bloodhound.hit_and_run.enabled' => true]);
    $this->member->forceFill(['role' => 'moderator'])->save();
    expect(obligationsOf($this))->toBe([]);
});
