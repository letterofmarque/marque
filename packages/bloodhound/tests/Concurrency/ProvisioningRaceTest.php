<?php

declare(strict_types=1);

// Job #109 step 5, on the real engines: a client announcing to twenty torrents
// fires twenty requests with one key at once, so two can both miss the key and
// both try to provision. Exactly one member comes out, by the unique index on
// announce_keys.key, not by checking first.
//
// The loser is forced deterministically: while our request is creating its
// member, a second connection commits the winner. SQLite takes one writer at a
// time for the whole database, so two provisioning transactions can't
// interleave there; this is a MySQL, MariaDB and PostgreSQL matter.

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Services\AnnounceKeyProvisioner;
use Marque\Bloodhound\Tests\TestUser;

const RACED = 'feedfacefeedfacefeedfacefeedface';

beforeEach(function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Interleaved writers are a MySQL, MariaDB and PostgreSQL matter.');
    }

    config(['database.connections.winner' => config('database.connections.testing')]);
});

afterEach(function () {
    DB::purge('winner');
});

/** The other request, committing its member and key first. */
function winnerCommits(string $email): int
{
    $winner = DB::connection('winner');
    $id = $winner->table('users')->insertGetId(['name' => 'Winner', 'email' => $email, 'password' => 'p']);
    $winner->table('announce_keys')->insert(['user_id' => $id, 'key' => RACED, 'created_at' => now()]);

    return $id;
}

it('returns the winner, and undoes its own member, when another request provisions the key first', function () {
    $winnerId = null;

    $user = app(AnnounceKeyProvisioner::class)->provision(RACED, function () use (&$winnerId) {
        $winnerId = winnerCommits('winner@example.com');

        return TestUser::create(['name' => 'Loser', 'email' => 'loser@example.com', 'password' => 'p']);
    });

    expect($user?->getAuthIdentifier())->toBe($winnerId)
        ->and(TestUser::query()->where('email', 'loser@example.com')->exists())->toBeFalse()
        ->and(AnnounceKey::query()->where('key', RACED)->count())->toBe(1);
});

// The same race where both requests create the same member (the old tracker's
// email), so ours trips the users table's unique email before the key.
it('returns the winner when its member collides on the member\'s own unique columns', function () {
    $winnerId = null;

    $user = app(AnnounceKeyProvisioner::class)->provision(RACED, function () use (&$winnerId) {
        $winnerId = winnerCommits('same@example.com');

        return TestUser::create(['name' => 'Same', 'email' => 'same@example.com', 'password' => 'p']);
    });

    expect($user?->getAuthIdentifier())->toBe($winnerId)
        ->and(TestUser::query()->where('email', 'same@example.com')->count())->toBe(1);
});

// The losing request created no one, so it runs no after-creation work:
// otherwise a ledger adjustment on its own database would outlive the member
// it was made for (found by the Job #109 check).
it('runs no after-creation work in the request that lost', function () {
    $ran = false;

    app(AnnounceKeyProvisioner::class)->provision(RACED, function () {
        winnerCommits('winner@example.com');

        return TestUser::create(['name' => 'Loser', 'email' => 'loser@example.com', 'password' => 'p']);
    }, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeFalse();
});
