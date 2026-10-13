<?php

declare(strict_types=1);

// Job #112 CP1 (Spec #121): leechtime exists at both levels seedtime does.
//
// Added by a NEW migration. Neither shipped users-table migration is edited —
// both have run in production databases, and editing a migration that has run
// is how you destroy one.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Tests\TestUser;

test('leechtime exists on the users table', function () {
    expect(Schema::hasColumn((new TestUser)->getTable(), 'leechtime'))->toBeTrue();
});

test('leechtime exists on torrent_user', function () {
    expect(Schema::hasColumn('torrent_user', 'leechtime'))->toBeTrue();
});

// Mirrors seedtime: a user who has never announced has leeched for zero
// seconds, which is a fact, not missing data.
test('leechtime defaults to zero on a new user', function () {
    $user = TestUser::create([
        'name' => 'Test User',
        'email' => 'leech@example.com',
        'password' => 'password',
    ]);

    $row = DB::table($user->getTable())->where('id', $user->getKey())->first();

    expect((int) $row->leechtime)->toBe(0)
        ->and((int) $row->seedtime)->toBe(0);
});

test('leechtime defaults to zero on a new torrent_user row', function () {
    DB::table('torrent_user')->insert([
        'user_id' => 1,
        'torrent_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('torrent_user')->first();

    expect((int) $row->leechtime)->toBe(0);
});
