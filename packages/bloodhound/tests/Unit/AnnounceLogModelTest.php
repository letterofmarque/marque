<?php

declare(strict_types=1);

// Spec #98: AnnounceLog's connection is swappable via
// config('bloodhound.announce_log.connection') — the actual mechanism that
// lets an operator isolate this write-heavy table on a separate database
// without any code change. Tested here in isolation from the write path
// (CP #533) because it's real logic, not schema.

use Marque\Bloodhound\Models\AnnounceLog;

test('uses the app default connection when none is configured', function () {
    config(['bloodhound.announce_log.connection' => null]);

    expect((new AnnounceLog)->getConnectionName())->toBeNull();
});

test('uses the configured connection name when set', function () {
    // Registered as a real connection, not just a name: the model resolves the
    // name lazily, but the test harness's own teardown tries to USE whatever
    // connection is configured. SQLite let a dangling name through because the
    // teardown never reached it; on a real engine it fails hard.
    config([
        'database.connections.announce_log_db' => config('database.connections.testing'),
        'bloodhound.announce_log.connection' => 'announce_log_db',
    ]);

    expect((new AnnounceLog)->getConnectionName())->toBe('announce_log_db');
});

test('is append-only — has no updated_at timestamp', function () {
    expect((new AnnounceLog)->usesTimestamps())->toBeTrue(); // created_at only, no updated_at column
    expect(AnnounceLog::UPDATED_AT)->toBeNull();
});
