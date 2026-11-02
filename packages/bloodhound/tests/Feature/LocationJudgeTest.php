<?php

declare(strict_types=1);

// Job #319: where a peer is, for the location limits, is a LocationJudge's
// question. The default locates a peer where its last announce came from;
// an app can bind its own.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Contracts\LocationJudge;
use Marque\Bloodhound\Services\AntiCheatService;
use Marque\Bloodhound\Support\DefaultLocationJudge;
use Marque\Bloodhound\Support\LocationQuestion;
use Marque\Bloodhound\Support\LocationVerdict;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => true]);

    $this->user = TestUser::create(['name' => 'Judge', 'email' => 'judge@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'judgejudgejudgejudgejudgejudge00');
    $this->torrent = Torrent::create([
        'name' => 'Judge', 'info_hash' => str_repeat('9', 40), 'size' => 1_000_000_000, 'user_id' => $this->user->id,
    ]);

    $this->t = Carbon::parse('2026-11-02 10:00:00');
});

/**
 * @return array<string, mixed>
 */
function judged($test, string $peer, string $ip, int $seconds, array $params = []): array
{
    $test->travelTo($test->t->copy()->addSeconds($seconds));

    return Bencode::decode($test->withoutMiddleware(BlockBrowsers::class)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/'.keyOf($test->user).'?'.http_build_query(array_merge([
            'info_hash' => hex2bin($test->torrent->info_hash), 'peer_id' => $peer, 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 500, 'compact' => 1, 'event' => 'started',
        ], $params)))
        ->assertOk()
        ->getContent());
}

it('judges by the default rule unless the app binds its own', function () {
    expect(app(LocationJudge::class))->toBeInstanceOf(DefaultLocationJudge::class);
});

it('asks the bound judge, and refuses with its reason', function () {
    app()->forgetInstance(AntiCheatService::class);
    app()->instance(LocationJudge::class, new class implements LocationJudge
    {
        public function judge(LocationQuestion $question): LocationVerdict
        {
            return LocationVerdict::refuse("Not from {$question->ip}");
        }
    });

    expect(judged($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0)['failure reason'])->toBe('Not from 203.0.113.7');
});

it('lets a bound judge pass what the default would refuse', function () {
    app()->forgetInstance(AntiCheatService::class);
    app()->instance(LocationJudge::class, new class implements LocationJudge
    {
        public function judge(LocationQuestion $question): LocationVerdict
        {
            return LocationVerdict::pass();
        }
    });

    judged($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0);

    expect(judged($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5))->not->toHaveKey('failure reason');
});

it('still holds a passed peer to the client limit, which is not a location question', function () {
    config(['bloodhound.anti_cheat.max_clients_per_torrent' => 1]);
    app()->forgetInstance(AntiCheatService::class);
    app()->instance(LocationJudge::class, new class implements LocationJudge
    {
        public function judge(LocationQuestion $question): LocationVerdict
        {
            return LocationVerdict::pass();
        }
    });

    judged($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0);

    expect(judged($this, '-qB4210-bbbbbbbbbbbb', '203.0.113.7', 5)['failure reason'])->toContain('Client limit reached');
});
