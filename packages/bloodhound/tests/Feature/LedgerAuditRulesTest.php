<?php

declare(strict_types=1);

// Job #193 (design 1.3 and 5.3): the audit checks what each row credited
// against its own reported, prior and timing values, and each peer's chain of
// baselines, without crying wolf on stops and refusals (F2).

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => false]);

    $this->user = TestUser::create(['name' => 'Audit', 'email' => 'audit-rules@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'auditrulesauditrulesauditrules00');
    $this->torrent = Torrent::create([
        'name' => 'Audit', 'info_hash' => str_repeat('d', 40), 'size' => 1_000_000, 'user_id' => $this->user->id,
    ]);

    $this->t = Carbon::parse('2026-10-17 10:00:00');
});

/**
 * A ledger row as written by 8.0, with whatever it is given.
 *
 * @param  array<string, mixed>  $attributes
 */
function ruleRow($test, int $minutes, array $attributes = []): AnnounceLog
{
    $row = new AnnounceLog;
    $row->forceFill(array_merge([
        'user_id' => $test->user->id,
        'torrent_id' => $test->torrent->id,
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'event' => 'regular',
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'upload_delta' => 0,
        'download_delta' => 0,
        'seed_seconds' => 0,
        'leech_seconds' => 0,
        'prior_up' => null,
        'prior_down' => null,
        'prior_at' => null,
        'anti_cheat_flagged' => false,
        'created_at' => $test->t->copy()->addMinutes($minutes),
    ], $attributes))->save();

    return $row;
}

/** A row continuing from the previous one at $fromMinutes, as the announce path writes it. */
function continuing($test, int $fromMinutes, int $minutes, int $priorUp, int $uploaded, array $attributes = []): AnnounceLog
{
    $seconds = min(($minutes - $fromMinutes) * 60, 3_600);

    return ruleRow($test, $minutes, array_merge([
        'uploaded' => $uploaded,
        'upload_delta' => $uploaded >= $priorUp ? $uploaded - $priorUp : $uploaded,
        'prior_up' => $priorUp,
        'prior_down' => 0,
        'prior_at' => $test->t->copy()->addMinutes($fromMinutes),
        'seed_seconds' => $seconds,
    ], $attributes));
}

function auditOf(): Marque\Bloodhound\Services\LedgerAudit
{
    return app(LedgerAuditor::class)->audit();
}

function auditAnnounce($test, array $params): void
{
    $query = http_build_query(array_merge([
        'info_hash' => hex2bin($test->torrent->info_hash),
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
    ], $params));

    $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/'.keyOf($test->user)."?{$query}")
        ->assertOk();
}

describe('through the tracker', function () {
    // The audit's A2 and A3 reproductions (F2): a session with a stop and a
    // refusal in it used to report two or three chain breaks.
    test('a session with a stop and a refusal in it audits clean', function () {
        $this->travelTo($this->t);
        auditAnnounce($this, ['event' => 'started']);
        $this->travelTo($this->t->copy()->addMinutes(30));
        auditAnnounce($this, ['uploaded' => 1_000]);

        config(['bloodhound.anti_cheat.enabled' => true]);
        $this->travelTo($this->t->copy()->addMinutes(31));
        auditAnnounce($this, ['uploaded' => 1_500, 'port' => 6881]);
        config(['bloodhound.anti_cheat.enabled' => false]);

        $this->travelTo($this->t->copy()->addMinutes(60));
        auditAnnounce($this, ['uploaded' => 2_000]);
        $this->travelTo($this->t->copy()->addMinutes(70));
        auditAnnounce($this, ['event' => 'stopped', 'uploaded' => 2_500]);
        $this->travelTo($this->t->copy()->addHours(5));
        auditAnnounce($this, ['event' => 'started']);
        $this->travelTo($this->t->copy()->addHours(5)->addMinutes(30));
        auditAnnounce($this, ['uploaded' => 400]);

        $audit = auditOf();

        expect(AnnounceLog::query()->count())->toBe(7)
            ->and(AnnounceLog::query()->where('anti_cheat_flagged', true)->count())->toBe(1)
            ->and($audit->arithmeticBreaks)->toBe([])
            ->and($audit->chainBreaks)->toBe([]);
    });
});

describe('arithmetic', function () {
    test('seconds must match the interval from prior_at, capped', function () {
        ruleRow($this, 0);
        continuing($this, 0, 30, 0, 100);
        continuing($this, 30, 60, 100, 200, ['seed_seconds' => 999]);
        continuing($this, 60, 600, 200, 300);

        $breaks = auditOf()->arithmeticBreaks;

        expect($breaks)->toHaveCount(1)
            ->and($breaks[0]['reason'])->toBe('seconds 999 seed + 0 leech, expected 1800');
    });

    test('an interval is all seed or all leech, never split', function () {
        ruleRow($this, 0);
        continuing($this, 0, 30, 0, 100, ['seed_seconds' => 900, 'leech_seconds' => 900]);

        expect(auditOf()->arithmeticBreaks)->toHaveCount(1);
    });

    test('a counter that went backwards is credited as reported', function () {
        ruleRow($this, 0, ['uploaded' => 5_000]);
        continuing($this, 0, 30, 5_000, 300);

        expect(AnnounceLog::query()->orderByDesc('id')->value('upload_delta'))->toBe(300)
            ->and(auditOf()->arithmeticBreaks)->toBe([]);

        AnnounceLog::query()->orderByDesc('id')->first()->forceFill(['upload_delta' => 0])->save();

        expect(auditOf()->arithmeticBreaks)->toHaveCount(1);
    });

    test('a forfeit may zero a direction; an accepted row may not', function () {
        ruleRow($this, 0);
        continuing($this, 0, 30, 0, 9_000, ['upload_delta' => 0, 'anti_cheat_flagged' => true, 'anti_cheat_reason' => 'forfeit']);

        expect(auditOf()->arithmeticBreaks)->toBe([]);

        AnnounceLog::query()->orderByDesc('id')->first()->forceFill(['anti_cheat_flagged' => false])->save();

        expect(auditOf()->arithmeticBreaks)->toHaveCount(1);
    });

    test('a row with no baseline must credit nothing', function () {
        ruleRow($this, 0, ['uploaded' => 5_000, 'upload_delta' => 5_000]);
        ruleRow($this, 1, ['peer_id' => '-qB4210-bbbbbbbbbbbb', 'seed_seconds' => 60]);

        expect(auditOf()->arithmeticBreaks)->toHaveCount(2);
    });

    test('rows written by 7.x are held to the rules they were written under', function () {
        ruleRow($this, 0, ['uploaded' => 5_000, 'prior_up' => 0, 'prior_down' => 0, 'upload_delta' => 5_000]);
        // 7.x clamped a backwards counter to 0, and wrote no seconds.
        ruleRow($this, 30, ['uploaded' => 300, 'prior_up' => 5_000, 'prior_down' => 0, 'upload_delta' => 0]);
        LedgerCursor::query()->where('stream', LedgerAuditor::TIME_COLUMNS_FROM)->update(['position' => AnnounceLog::max('id')]);

        expect(auditOf()->arithmeticBreaks)->toBe([]);
    });
});

describe('the chain', function () {
    test('a baseline after a stop is a break', function () {
        ruleRow($this, 0);
        ruleRow($this, 30, ['event' => 'stopped', 'uploaded' => 100, 'upload_delta' => 100, 'prior_up' => 0, 'prior_down' => 0, 'prior_at' => $this->t, 'seed_seconds' => 1_800]);
        continuing($this, 30, 90, 100, 200);

        $breaks = auditOf()->chainBreaks;

        expect($breaks)->toHaveCount(1)
            ->and($breaks[0]['reason'])->toBe('a baseline after the session stopped');
    });

    test('no baseline after a live announce is a lost peer, within retention', function () {
        ruleRow($this, 0);
        continuing($this, 0, 30, 0, 100);
        ruleRow($this, 60, ['uploaded' => 900]);

        $breaks = auditOf()->chainBreaks;

        expect($breaks)->toHaveCount(1)
            ->and($breaks[0]['reason'])->toBe('Redis lost this peer: its next announce had no baseline');
    });

    test('no baseline after a gap longer than retention is expected', function () {
        config(['bloodhound.baseline_retention' => 86_400]);
        ruleRow($this, 0);
        ruleRow($this, 60 * 25, ['uploaded' => 900]);

        expect(auditOf()->chainBreaks)->toBe([]);
    });

    test('two rows diffed against the same baseline are one interval credited twice', function () {
        ruleRow($this, 0);
        continuing($this, 0, 30, 0, 100);
        continuing($this, 0, 31, 0, 150);

        $breaks = auditOf()->chainBreaks;

        expect($breaks)->toHaveCount(1)
            ->and($breaks[0]['reason'])->toBe('duplicate interval: two rows diffed against the same baseline');
    });

    test('a baseline whose time does not continue the previous announce is a break', function () {
        ruleRow($this, 0);
        continuing($this, 0, 30, 0, 100);
        continuing($this, 20, 60, 100, 200);

        expect(auditOf()->chainBreaks)->toHaveCount(1);
    });

    test('refusals and opening balances take no part', function () {
        ruleRow($this, 0);
        ruleRow($this, 10, ['anti_cheat_flagged' => true, 'anti_cheat_reason' => 'Port 6881 is blacklisted', 'uploaded' => 50]);
        continuing($this, 0, 30, 0, 100);

        expect(auditOf()->chainBreaks)->toBe([]);
    });

    test('chains are per member: another member\'s peer_id is not this one\'s', function () {
        $other = TestUser::create(['name' => 'O', 'email' => 'audit-other@example.com', 'password' => 'p']);
        ruleRow($this, 0, ['uploaded' => 7_000]);
        ruleRow($this, 10, ['user_id' => $other->id]);

        expect(auditOf()->chainBreaks)->toBe([]);
    });

    test('a 7.x stop with no baseline is not a break', function () {
        ruleRow($this, 0, ['prior_up' => 0, 'prior_down' => 0]);
        ruleRow($this, 30, ['event' => 'stopped', 'uploaded' => 100]);
        LedgerCursor::query()->where('stream', LedgerAuditor::TIME_COLUMNS_FROM)->update(['position' => AnnounceLog::max('id')]);

        expect(auditOf()->chainBreaks)->toBe([]);
    });
});

test('the boundary migration records where 8.0 rows begin', function () {
    expect(DB::table('ledger_cursors')->where('stream', LedgerAuditor::TIME_COLUMNS_FROM)->exists())->toBeTrue();
});
