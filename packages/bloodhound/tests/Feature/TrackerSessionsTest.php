<?php

declare(strict_types=1);

// Job #193 (D8): a member ends their own session. A client that restarted with
// a new peer ID at a new location is refused by the leeching limit (1) until
// its old session expires; ending the old one frees the member at once. The
// cost is whatever the old session moved since its last announce. An 'ended'
// row records it: it credits nothing and closes the chain like a stop.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\RedisDurability;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Contracts\TrackerSessionsInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\TrackerSession;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => true]);

    $this->user = TestUser::create(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'sessionssessionssessionssessions');
    $this->other = TestUser::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'p']);
    issueAnnounceKey($this->other, 'othersothersothersothersothers00');

    $this->torrent = Torrent::create([
        'name' => 'Big Buck Bunny', 'info_hash' => str_repeat('d', 40), 'size' => 1_000_000_000, 'user_id' => $this->user->id,
    ]);

    $this->sessions = app(TrackerSessionsInterface::class);
    $this->t = Carbon::parse('2026-10-18 10:00:00');
    $this->travelTo($this->t);
});

/**
 * One announce, $seconds after the start. Returns the body.
 */
function sessionAnnounce($test, string $peer, string $ip, int $seconds, array $params = [], ?TestUser $user = null, ?Torrent $torrent = null): string
{
    $test->travelTo($test->t->copy()->addSeconds($seconds));
    $user ??= $test->user;
    $torrent ??= $test->torrent;

    $query = http_build_query(array_merge([
        'info_hash' => hex2bin($torrent->info_hash),
        'peer_id' => $peer,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
    ], $params));

    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/'.keyOf($user)."?{$query}")
        ->assertOk()
        ->getContent();
}

function sessionRefused(string $body): bool
{
    return str_contains($body, 'failure reason');
}

describe('listing', function () {
    test('lists the member\'s own live sessions, leeching first', function () {
        $second = Torrent::create(['name' => 'Sintel', 'info_hash' => str_repeat('e', 40), 'size' => 1_000, 'user_id' => $this->user->id]);

        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'uploaded' => 300], torrent: $second);
        sessionAnnounce($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.2', 10, ['event' => 'started', 'left' => 500, 'downloaded' => 40]);
        sessionAnnounce($this, '-qB4210-cccccccccccc', '10.0.0.3', 20, ['event' => 'started'], user: $this->other);

        $sessions = $this->sessions->sessionsFor($this->user);

        expect($sessions)->toHaveCount(2)
            ->and($sessions[0])->toBeInstanceOf(TrackerSession::class)
            ->and($sessions[0]->torrentName)->toBe('Big Buck Bunny')
            ->and($sessions[0]->seeding)->toBeFalse()
            ->and($sessions[0]->ip)->toBe('10.0.0.2')
            ->and($sessions[0]->client)->toBe('qBittorrent/4.5.0')
            ->and($sessions[0]->downloaded)->toBe(40)
            ->and($sessions[0]->lastAnnounceAt->getTimestamp())->toBe($this->t->copy()->addSeconds(10)->getTimestamp())
            ->and($sessions[1]->torrentName)->toBe('Sintel')
            ->and($sessions[1]->seeding)->toBeTrue()
            ->and($sessions[1]->uploaded)->toBe(300);
    });

    test('leaves out a session that has expired or stopped', function () {
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);
        sessionAnnounce($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.2', 0, ['event' => 'started']);
        sessionAnnounce($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.2', 5, ['event' => 'stopped']);

        $this->travelTo($this->t->copy()->addSeconds((int) config('threepio.peer_expiry', 3600) + 1));

        expect($this->sessions->sessionsFor($this->user))->toBe([]);
    });
});

describe('ending a session', function () {
    // The case D8 exists for.
    test('frees the leeching location for the member\'s restarted client', function () {
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'left' => 500]);

        $body = sessionAnnounce($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.2', 60, ['event' => 'started', 'left' => 500]);
        expect(sessionRefused($body))->toBeTrue()
            ->and($body)->toContain('end the other session');

        expect($this->sessions->endSession($this->user, $this->sessions->sessionsFor($this->user)[0]->id))->toBeTrue();

        expect(sessionRefused(sessionAnnounce($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.2', 70, ['event' => 'started', 'left' => 500])))->toBeFalse()
            ->and($this->sessions->sessionsFor($this->user))->toHaveCount(1);
    });

    test('writes an ended row that credits nothing, and the ledger stays coherent', function () {
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'uploaded' => 100, 'left' => 500]);
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 1_800, ['uploaded' => 400, 'left' => 500]);

        $this->travelTo($this->t->copy()->addSeconds(2_000));
        $this->sessions->endSession($this->user, $this->sessions->sessionsFor($this->user)[0]->id);

        $row = AnnounceLog::query()->orderByDesc('id')->first();

        expect($row->event)->toBe(AnnounceLog::EVENT_ENDED)
            ->and($row->peer_id)->toBe('-qB4210-aaaaaaaaaaaa')
            ->and($row->ip)->toBe('10.0.0.1')
            ->and($row->uploaded)->toBe(400)
            ->and($row->upload_delta + $row->download_delta + $row->seed_seconds + $row->leech_seconds)->toBe(0)
            ->and($row->prior_up)->toBeNull()
            ->and($row->prior_at)->toBeNull()
            ->and($row->anti_cheat_flagged)->toBeFalse()
            ->and($row->created_at->getTimestamp())->toBe($this->t->copy()->addSeconds(2_000)->getTimestamp());

        // The old client comes back with the same peer ID: a new session,
        // with no baseline, and no break reported for it.
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 2_100, ['uploaded' => 900, 'left' => 500]);

        expect(AnnounceLog::query()->orderByDesc('id')->first()->prior_up)->toBeNull();

        app(LedgerAggregator::class)->run();

        expect(app(LedgerAuditor::class)->audit()->hasBreaks())->toBeFalse()
            ->and(app(LedgerAuditor::class)->reconcile()->userDrift)->toBe([])
            ->and($this->user->fresh()->uploaded)->toBe(300);
    });

    test('removes the peer from the swarm and the torrent\'s counts', function () {
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'left' => 500]);

        expect($this->torrent->fresh()->leechers)->toBe(1);

        $this->sessions->endSession($this->user, $this->sessions->sessionsFor($this->user)[0]->id);

        expect(app(PeerService::class)->find($this->torrent->id, '-qB4210-aaaaaaaaaaaa'))->toBeNull()
            ->and(app(PeerService::class)->getLeechers($this->torrent->id))->toBe(0)
            ->and($this->torrent->fresh()->leechers)->toBe(0);
    });

    test('ends a session whose peer ID is not text', function () {
        $peerId = "-qB4210-\x00\xff:\x80abcdefgh";
        sessionAnnounce($this, $peerId, '10.0.0.1', 0, ['event' => 'started']);

        $session = $this->sessions->sessionsFor($this->user)[0];

        expect($this->sessions->endSession($this->user, $session->id))->toBeTrue()
            ->and(app(PeerService::class)->find($this->torrent->id, $peerId))->toBeNull()
            ->and(AnnounceLog::query()->orderByDesc('id')->first()->peer_id)->toBe('x:'.bin2hex($peerId));
    });

    test('is refused for another member\'s session, which is left as it was', function () {
        sessionAnnounce($this, '-qB4210-cccccccccccc', '10.0.0.3', 0, ['event' => 'started'], user: $this->other);
        $theirs = $this->sessions->sessionsFor($this->other)[0];
        $rows = AnnounceLog::query()->count();

        expect($this->sessions->endSession($this->user, $theirs->id))->toBeFalse()
            ->and($this->sessions->sessionsFor($this->other))->toHaveCount(1)
            ->and(AnnounceLog::query()->count())->toBe($rows);
    });

    test('answers false for a session already ended, or an id that is not one', function () {
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);
        $id = $this->sessions->sessionsFor($this->user)[0]->id;

        expect($this->sessions->endSession($this->user, $id))->toBeTrue()
            ->and($this->sessions->endSession($this->user, $id))->toBeFalse()
            ->and($this->sessions->endSession($this->user, 'nonsense'))->toBeFalse()
            ->and($this->sessions->endSession($this->user, '12:zz'))->toBeFalse()
            ->and($this->sessions->endSession($this->user, ''))->toBeFalse()
            ->and(AnnounceLog::query()->where('event', AnnounceLog::EVENT_ENDED)->count())->toBe(1);
    });

    // A retained baseline would credit the ended session's interval to the
    // next announce under its peer ID; ending it discards that too.
    test('leaves no retained baseline behind', function () {
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'uploaded' => 100]);
        $id = $this->sessions->sessionsFor($this->user)[0]->id;
        $this->sessions->endSession($this->user, $id);

        $lookup = app(PeerService::class)->lookup($this->torrent->id, '-qB4210-aaaaaaaaaaaa', $this->user->id);

        expect($lookup->baseline)->toBeNull();
    });

    // Recovery reads the ledger for a baseline after Redis is lost; after an
    // 'ended' row, as after a stop, there is none.
    test('gives recovery no baseline after it', function () {
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'uploaded' => 100]);
        $this->sessions->endSession($this->user, $this->sessions->sessionsFor($this->user)[0]->id);

        Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
        app(RedisDurability::class)->openRecoveryWindow();

        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 600, ['uploaded' => 500]);

        $row = AnnounceLog::query()->orderByDesc('id')->first();

        expect($row->prior_up)->toBeNull()
            ->and($row->upload_delta)->toBe(0)
            ->and(app(LedgerAuditor::class)->audit()->hasBreaks())->toBeFalse();
    });
});

describe('the audit', function () {
    // An 'ended' row closes the session as a stop does: a row after it that
    // claims a baseline is reported.
    test('reports a baseline after an ended session', function () {
        sessionAnnounce($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'uploaded' => 100]);
        $this->sessions->endSession($this->user, $this->sessions->sessionsFor($this->user)[0]->id);

        $ended = AnnounceLog::query()->orderByDesc('id')->first();

        (new AnnounceLog)->forceFill([
            'user_id' => $this->user->id, 'torrent_id' => $this->torrent->id, 'peer_id' => '-qB4210-aaaaaaaaaaaa',
            'event' => 'regular', 'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => 200, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => 100, 'download_delta' => 0, 'seed_seconds' => 60, 'leech_seconds' => 0,
            'prior_up' => 100, 'prior_down' => 0, 'prior_at' => $ended->created_at,
            'anti_cheat_flagged' => false, 'created_at' => $ended->created_at->copy()->addSeconds(60),
        ])->save();

        expect(collect(app(LedgerAuditor::class)->audit()->chainBreaks)->pluck('reason')->all())
            ->toBe(['a baseline after the session ended']);
    });
});
