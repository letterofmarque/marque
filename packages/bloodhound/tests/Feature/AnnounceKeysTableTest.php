<?php

declare(strict_types=1);

// Build #113 CP4 (Spec #119 criterion 8). Announce keys move out of the
// consumer's users table into announce_keys, which bloodhound owns outright.
// users.announce_key is left in place, deprecated: nothing reads it and
// nothing writes it.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Bloodhound\Tests\TrackerUser;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Models\Torrent;

function plainUser(string $email): TestUser
{
    return TestUser::create(['name' => 'Key User', 'email' => $email, 'password' => 'password']);
}

describe('schema', function () {
    it('creates the table bloodhound owns', function () {
        expect(Schema::hasTable('announce_keys'))->toBeTrue();

        foreach (['user_id', 'key', 'created_at'] as $column) {
            expect(Schema::hasColumn('announce_keys', $column))->toBeTrue();
        }
    });

    // The reason this CP was pulled forward: users.announce_key is
    // varchar(32), but key_pattern is configurable and documented as
    // widenable. SQLite ignores varchar length, so only the strict engines
    // could ever show this.
    it('stores keys longer than 32 characters', function (int $length) {
        $user = plainUser("long{$length}@example.com");

        issueAnnounceKey($user, str_repeat('k', $length));

        expect(AnnounceKey::where('user_id', $user->id)->value('key'))->toBe(str_repeat('k', $length));
    })->with([40, 64, 255]);

    it('refuses the same key for two users', function () {
        issueAnnounceKey(plainUser('one@example.com'), str_repeat('d', 32));

        $second = plainUser('two@example.com');

        // In a savepoint: PostgreSQL aborts the whole transaction on a
        // constraint violation, and without one the next query in teardown
        // fails instead — reported against whichever test runs it.
        expect(fn () => DB::transaction(fn () => issueAnnounceKey($second, str_repeat('d', 32))))
            ->toThrow(Exception::class);
    });

    it('holds one key per user', function () {
        $user = plainUser('single@example.com');
        issueAnnounceKey($user, str_repeat('e', 32));

        expect(fn () => DB::transaction(fn () => issueAnnounceKey($user, str_repeat('f', 32))))
            ->toThrow(Exception::class);
    });
});

describe('the backfill', function () {
    // Run the migration again against users that only have the legacy column,
    // which is exactly the state of every existing install on upgrade.
    it('copies every existing key out of users.announce_key', function () {
        Schema::drop('announce_keys');

        $withKey = plainUser('legacy@example.com');
        $withoutKey = plainUser('nokey@example.com');
        TestUser::whereKey($withKey->id)->update(['announce_key' => 'legacylegacylegacylegacylegacy00']);

        $migration = require __DIR__.'/../../database/migrations/2026_09_25_000001_create_announce_keys_table.php';
        $migration->up();

        expect(AnnounceKey::where('user_id', $withKey->id)->value('key'))->toBe('legacylegacylegacylegacylegacy00')
            ->and(AnnounceKey::where('user_id', $withoutKey->id)->exists())->toBeFalse();
    });

    // Only ever inserts: the legacy column keeps its data, so a consumer who
    // rolls back loses nothing.
    it('leaves users.announce_key exactly as it was', function () {
        Schema::drop('announce_keys');

        $user = plainUser('keep@example.com');
        TestUser::whereKey($user->id)->update(['announce_key' => 'keepkeepkeepkeepkeepkeepkeepkeep']);

        (require __DIR__.'/../../database/migrations/2026_09_25_000001_create_announce_keys_table.php')->up();

        expect(DB::table('users')->where('id', $user->id)->value('announce_key'))
            ->toBe('keepkeepkeepkeepkeepkeepkeepkeep');
    });
});

describe('the announce path', function () {
    beforeEach(function () {
        Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

        $this->user = plainUser('announce@example.com');
        $this->torrent = Torrent::create([
            'name' => 'Key Torrent',
            'info_hash' => str_repeat('c', 40),
            'size' => 1_000,
            'user_id' => $this->user->id,
        ]);
    });

    $announce = function ($test, string $key) {
        return decodeTracker(trackerRequest($test, "/announce/{$key}?".http_build_query([
            'info_hash' => hex2bin(str_repeat('c', 40)),
            'peer_id' => '-qB4500-keytablepeer',
            'port' => 51413,
            'uploaded' => 0,
            'downloaded' => 0,
            'left' => 0,
        ])));
    };

    it('resolves the user through announce_keys', function () use ($announce) {
        issueAnnounceKey($this->user, 'tabletabletabletabletabletable00');

        expect($announce($this, 'tabletabletabletabletabletable00'))->not->toHaveKey('failure reason');
    });

    // The column is dead. A key that exists only there is not a key.
    it('does not honour a key that exists only in users.announce_key', function () use ($announce) {
        TestUser::whereKey($this->user->id)->update(['announce_key' => 'columncolumncolumncolumncolumn00']);

        expect($announce($this, 'columncolumncolumncolumncolumn00')['failure reason'] ?? null)
            ->toBe('Unknown announce key');
    });

    // The hot path: the move must not add a query per announce. One statement
    // resolves the user, with the key lookup as a subquery.
    it('finds the user in a single query', function () use ($announce) {
        issueAnnounceKey($this->user, 'singlesinglesinglesinglesingle00');

        DB::enableQueryLog();
        $announce($this, 'singlesinglesinglesinglesingle00');
        $keyQueries = array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'announce_keys'));
        DB::disableQueryLog();

        expect($keyQueries)->toHaveCount(1)
            ->and(array_values($keyQueries)[0]['query'])->toContain('users');
    });
});

describe('HasTrackerStats', function () {
    it('issues a key into announce_keys when a user is created', function () {
        $user = TrackerUser::create(['name' => 'Trait User', 'email' => 'trait@example.com', 'password' => 'password']);

        $key = app(TrackerStatsInterface::class)->announceKeyFor($user);

        expect($key)->not->toBeNull()
            ->and(DB::table('users')->where('id', $user->id)->value('announce_key'))->toBeNull();
    });

    // Removed deliberately (Dan, 2026-09-25): key handling belongs to the
    // tracker's service, not to the consumer's model.
    it('no longer puts key methods on the consumer\'s model', function () {
        expect(method_exists(TrackerUser::class, 'regenerateAnnounceKey'))->toBeFalse()
            ->and(method_exists(TrackerUser::class, 'generateAnnounceKey'))->toBeFalse();
    });
});
