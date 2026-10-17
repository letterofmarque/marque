<?php

declare(strict_types=1);

// Job #193 (design addendum A4): members register seedboxes, admins approve or
// revoke them, and an approved seedbox's announces are held to the seedbox
// speed cap. bloodhound owns the data and binds trove's SeedboxesInterface;
// whatever renders screens (usarrs) goes through the contract.

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Contracts\SeedboxRegistry;
use Marque\Bloodhound\Events\SeedboxApproved;
use Marque\Bloodhound\Events\SeedboxRegistered;
use Marque\Bloodhound\Events\SeedboxRevoked;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\Seedbox;

beforeEach(function () {
    $this->member = TestUser::create(['name' => 'Member', 'email' => 'seedbox-member@example.com', 'password' => 'p']);
    $this->admin = TestUser::create(['name' => 'Admin', 'email' => 'seedbox-admin@example.com', 'password' => 'p']);
    $this->seedboxes = app(SeedboxesInterface::class);
    $this->registry = app(SeedboxRegistry::class);
});

describe('registering', function () {
    test('a single address is stored as its own /32 or /128, pending approval', function () {
        $v4 = $this->seedboxes->register($this->member, '203.0.113.7', 'Hetzner');
        $v6 = $this->seedboxes->register($this->member, '2001:db8::7');

        expect($v4->network)->toBe('203.0.113.7/32')
            ->and($v4->label)->toBe('Hetzner')
            ->and($v4->status)->toBe(Seedbox::PENDING)
            ->and($v6->network)->toBe('2001:db8::7/128');
    });

    test('a range is normalised to its network address', function () {
        expect($this->seedboxes->register($this->member, '203.0.113.99/24')->network)->toBe('203.0.113.0/24')
            ->and($this->seedboxes->register($this->member, '2001:db8:aa:bb::1/64')->network)->toBe('2001:db8:aa:bb::/64');
    });

    test('a malformed network is refused', function () {
        $this->seedboxes->register($this->member, 'not-an-address');
    })->throws(InvalidArgumentException::class);

    test('a range wider than the tracker allows is refused', function () {
        expect(fn () => $this->seedboxes->register($this->member, '203.0.0.0/16'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->seedboxes->register($this->member, '2001:db8::/32'))->toThrow(InvalidArgumentException::class);
    });

    test('the same network twice is refused', function () {
        $this->seedboxes->register($this->member, '203.0.113.7');

        $this->seedboxes->register($this->member, '203.0.113.7/32');
    })->throws(InvalidArgumentException::class);

    test('without required approval, registering approves', function () {
        config(['bloodhound.anti_cheat.seedbox.require_approval' => false]);

        $seedbox = app(SeedboxesInterface::class)->register($this->member, '203.0.113.7');

        expect($seedbox->isApproved())->toBeTrue()
            ->and(app(SeedboxesInterface::class)->requiresApproval())->toBeFalse()
            ->and(app(SeedboxRegistry::class)->isSeedbox($this->member->id, '203.0.113.7'))->toBeTrue();
    });
});

describe('approval decides the cap', function () {
    test('a pending seedbox is not a seedbox; an approved one is, for its member and its range only', function () {
        $seedbox = $this->seedboxes->register($this->member, '203.0.113.0/24');

        expect($this->registry->isSeedbox($this->member->id, '203.0.113.50'))->toBeFalse();

        $approved = $this->seedboxes->approve($seedbox->id, $this->admin);

        expect($approved->isApproved())->toBeTrue()
            ->and($approved->decidedBy)->toBe($this->admin->id)
            ->and($approved->decidedAt)->not->toBeNull()
            ->and($this->registry->isSeedbox($this->member->id, '203.0.113.50'))->toBeTrue()
            ->and($this->registry->isSeedbox($this->member->id, '203.0.114.50'))->toBeFalse()
            ->and($this->registry->isSeedbox($this->admin->id, '203.0.113.50'))->toBeFalse();
    });

    test('an IPv6 range matches the addresses inside it', function () {
        $this->seedboxes->approve($this->seedboxes->register($this->member, '2001:db8:aa:bb::/64')->id, $this->admin);

        expect($this->registry->isSeedbox($this->member->id, '2001:db8:aa:bb:1:2:3:4'))->toBeTrue()
            ->and($this->registry->isSeedbox($this->member->id, '2001:db8:aa:bc::1'))->toBeFalse()
            ->and($this->registry->isSeedbox($this->member->id, '203.0.113.7'))->toBeFalse();
    });

    test('revoking takes effect at once, despite the cache', function () {
        $seedbox = $this->seedboxes->register($this->member, '203.0.113.7');
        $this->seedboxes->approve($seedbox->id, $this->admin);

        expect($this->registry->isSeedbox($this->member->id, '203.0.113.7'))->toBeTrue();

        $revoked = $this->seedboxes->revoke($seedbox->id, $this->admin);

        expect($revoked->status)->toBe(Seedbox::REVOKED)
            ->and($this->registry->isSeedbox($this->member->id, '203.0.113.7'))->toBeFalse();
    });

    // The lookup is on the announce path. Once a member's networks are cached,
    // asking costs no query.
    test('a cached member is answered without a query', function () {
        $this->seedboxes->approve($this->seedboxes->register($this->member, '203.0.113.7')->id, $this->admin);
        $this->registry->isSeedbox($this->member->id, '203.0.113.7');

        DB::enableQueryLog();
        $this->registry->isSeedbox($this->member->id, '203.0.113.7');
        $this->registry->isSeedbox($this->member->id, '198.51.100.1');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBe(0);
    });

    test('approve, register and revoke each fire an event', function () {
        Event::fake([SeedboxRegistered::class, SeedboxApproved::class, SeedboxRevoked::class]);

        $seedbox = $this->seedboxes->register($this->member, '203.0.113.7');
        $this->seedboxes->approve($seedbox->id, $this->admin);
        $this->seedboxes->revoke($seedbox->id, $this->admin);

        Event::assertDispatched(SeedboxRegistered::class, fn ($event) => $event->seedbox->id === $seedbox->id);
        Event::assertDispatched(SeedboxApproved::class, fn ($event) => $event->by === $this->admin->id);
        Event::assertDispatched(SeedboxRevoked::class);
    });
});

describe('listing and removing', function () {
    test('a member sees only their own, newest first; an admin sees all, by status', function () {
        $first = $this->seedboxes->register($this->member, '203.0.113.7');
        $second = $this->seedboxes->register($this->member, '203.0.113.8');
        $theirs = $this->seedboxes->register($this->admin, '198.51.100.1');
        $this->seedboxes->approve($second->id, $this->admin);

        expect(array_map(fn ($s) => $s->id, $this->seedboxes->forUser($this->member)))->toBe([$second->id, $first->id])
            ->and(array_map(fn ($s) => $s->id, $this->seedboxes->all(Seedbox::PENDING)))->toBe([$theirs->id, $first->id])
            ->and($this->seedboxes->all())->toHaveCount(3);
    });

    test('a member removes their own; another member\'s is not found', function () {
        $mine = $this->seedboxes->register($this->member, '203.0.113.7');
        $this->seedboxes->approve($mine->id, $this->admin);
        $this->registry->isSeedbox($this->member->id, '203.0.113.7');

        expect(fn () => $this->seedboxes->remove($this->admin, $mine->id))->toThrow(ModelNotFoundException::class);

        $this->seedboxes->remove($this->member, $mine->id);

        expect($this->seedboxes->forUser($this->member))->toBe([])
            ->and($this->registry->isSeedbox($this->member->id, '203.0.113.7'))->toBeFalse();
    });
});

describe('the commands', function () {
    test('list, approve and revoke from the console, recording who', function () {
        $seedbox = $this->seedboxes->register($this->member, '203.0.113.7', 'Box');

        $this->artisan('bloodhound:seedbox', ['action' => 'list'])
            ->expectsOutputToContain('203.0.113.7/32')
            ->assertSuccessful();

        $this->artisan('bloodhound:seedbox', ['action' => 'approve', 'id' => $seedbox->id, '--by' => $this->admin->id])->assertSuccessful();

        expect($this->seedboxes->forUser($this->member)[0]->decidedBy)->toBe($this->admin->id);

        $this->artisan('bloodhound:seedbox', ['action' => 'revoke', 'id' => $seedbox->id, '--by' => $this->admin->id])->assertSuccessful();

        expect($this->seedboxes->forUser($this->member)[0]->status)->toBe(Seedbox::REVOKED);
    });

    test('approving needs to know who is approving', function () {
        $seedbox = $this->seedboxes->register($this->member, '203.0.113.7');

        $this->artisan('bloodhound:seedbox', ['action' => 'approve', 'id' => $seedbox->id])->assertFailed();
    });
});

// End to end: the registry the announce path uses is the stored one.
test('an announce from an approved seedbox is held to the seedbox cap', function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config([
        'bloodhound.anti_cheat.enabled' => true,
        'bloodhound.anti_cheat.min_announce_gap' => 0,
        'bloodhound.anti_cheat.max_upload_speed' => 1_000,
        'bloodhound.anti_cheat.seedbox.max_upload_speed' => 1_000_000,
    ]);
    issueAnnounceKey($this->member, 'seedboxseedboxseedboxseedbox0000');
    $torrent = Torrent::create(['name' => 'S', 'info_hash' => str_repeat('9', 40), 'size' => 1_000_000_000, 'user_id' => $this->member->id]);
    $this->seedboxes->approve($this->seedboxes->register($this->member, '203.0.113.7')->id, $this->admin);

    $announce = function (int $seconds, int $uploaded, string $event = '') use ($torrent) {
        $this->travelTo(now()->startOfMinute()->addSeconds($seconds));

        $this->withoutMiddleware(BlockBrowsers::class)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/announce/'.keyOf($this->member).'?'.http_build_query(array_filter([
                'info_hash' => hex2bin($torrent->info_hash), 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'port' => 51413,
                'uploaded' => $uploaded, 'downloaded' => 0, 'left' => 0, 'event' => $event, 'compact' => 1,
            ], fn ($value) => $value !== '')))->assertOk();
    };

    $announce(0, 0, 'started');
    $announce(10, 50_000);

    expect(AnnounceLog::query()->orderByDesc('id')->value('upload_delta'))->toBe(50_000);
});
