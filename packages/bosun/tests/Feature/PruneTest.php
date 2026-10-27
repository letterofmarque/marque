<?php

declare(strict_types=1);

use Marque\Bosun\Models\StaffActionEntry;

function entryAged(int $days): StaffActionEntry
{
    return StaffActionEntry::query()->create([
        'actor_id' => 1,
        'action' => 'usarrs.user.banned',
        'package' => 'usarrs',
        'subject_type' => 'user',
        'subject_id' => '2',
        'created_at' => now()->subDays($days),
    ]);
}

it('keeps everything when no retention is set', function () {
    entryAged(4000);

    $this->artisan('bosun:prune')
        ->expectsOutputToContain('kept for good')
        ->assertSuccessful();

    expect(StaffActionEntry::query()->count())->toBe(1);
});

it('deletes only what is older than the retention period', function () {
    config(['bosun.retention_days' => 365]);
    entryAged(400);
    $kept = entryAged(10);

    $this->artisan('bosun:prune')
        ->expectsOutputToContain('Deleted 1')
        ->assertSuccessful();

    expect(StaffActionEntry::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('takes --days over the config, and refuses nonsense', function () {
    config(['bosun.retention_days' => 365]);
    entryAged(40);

    $this->artisan('bosun:prune', ['--days' => '30'])->assertSuccessful();
    expect(StaffActionEntry::query()->count())->toBe(0);

    $this->artisan('bosun:prune', ['--days' => 'ever'])->assertFailed();
});
