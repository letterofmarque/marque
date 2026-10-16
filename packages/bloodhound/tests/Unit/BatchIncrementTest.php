<?php

declare(strict_types=1);

// Job #112, correction to CP #651: how a batch adds its amounts to many rows
// in one statement, per engine.
//
// The SQL is pinned per driver because one suite run exercises one engine;
// the behaviour is exercised on every engine by the aggregation tests.

use Marque\Bloodhound\Support\BatchIncrement;

$amounts = [
    7 => ['uploaded' => 10, 'seedtime' => 20],
    9 => ['uploaded' => 30, 'seedtime' => 40],
];

test('PostgreSQL joins a typed VALUES list', function () use ($amounts) {
    [$sql, $bindings] = (new BatchIncrement('pgsql'))->statement('"torrent_user"', '"id"', ['"uploaded"', '"seedtime"'], ['uploaded', 'seedtime'], $amounts);

    expect($sql)->toBe(
        'WITH "batch_amounts" ("id", "uploaded", "seedtime") AS (VALUES '
        .'(CAST(? AS BIGINT), CAST(? AS BIGINT), CAST(? AS BIGINT)), (CAST(? AS BIGINT), CAST(? AS BIGINT), CAST(? AS BIGINT))) '
        .'UPDATE "torrent_user" SET "uploaded" = "torrent_user"."uploaded" + "batch_amounts"."uploaded", '
        .'"seedtime" = "torrent_user"."seedtime" + "batch_amounts"."seedtime" '
        .'FROM "batch_amounts" WHERE "torrent_user"."id" = "batch_amounts"."id"'
    )->and($bindings)->toBe([7, 10, 20, 9, 30, 40]);
});

test('SQLite uses the same form', function () use ($amounts) {
    [$sql] = (new BatchIncrement('sqlite'))->statement('"torrent_user"', '"id"', ['"uploaded"', '"seedtime"'], ['uploaded', 'seedtime'], $amounts);

    expect($sql)->toStartWith('WITH "batch_amounts" ("id", "uploaded", "seedtime") AS (VALUES ')
        ->and($sql)->toContain('FROM "batch_amounts" WHERE "torrent_user"."id" = "batch_amounts"."id"');
});

test('MySQL and MariaDB join a UNION ALL derived table', function (string $driver) use ($amounts) {
    [$sql, $bindings] = (new BatchIncrement($driver))->statement('`torrent_user`', '`id`', ['`uploaded`', '`seedtime`'], ['uploaded', 'seedtime'], $amounts);

    expect($sql)->toBe(
        'UPDATE `torrent_user` JOIN (SELECT ? AS `id`, ? AS `uploaded`, ? AS `seedtime` UNION ALL SELECT ?, ?, ?) AS `batch_amounts` '
        .'ON `torrent_user`.`id` = `batch_amounts`.`id` '
        .'SET `torrent_user`.`uploaded` = `torrent_user`.`uploaded` + `batch_amounts`.`uploaded`, '
        .'`torrent_user`.`seedtime` = `torrent_user`.`seedtime` + `batch_amounts`.`seedtime`'
    )->and($bindings)->toBe([7, 10, 20, 9, 30, 40]);
})->with(['mysql', 'mariadb']);

test('an unsupported driver is refused by name', function () {
    new BatchIncrement('sqlsrv');
})->throws(InvalidArgumentException::class, 'sqlsrv');
