<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Bosun\Models\StaffPresence;
use Marque\Bosun\Tests\TestUser;

/**
 * When staff were last on the site, for the Staff screen. Staff only, and a
 * member's activity is never recorded.
 */
beforeEach(function () {
    Route::middleware('web')->get('/bosun-ping', fn () => 'ok');
});

it('stamps a moderator, an admin and the owner', function (string $role) {
    $staff = TestUser::factory()->create(['role' => $role]);

    $this->actingAs($staff)->get('/bosun-ping')->assertOk();

    expect(StaffPresence::query()->find($staff->id)?->last_seen_at)->not->toBeNull();
})->with(['moderator', 'admin', 'owner']);

it('never stamps a member, an uploader or a guest', function () {
    $this->get('/bosun-ping')->assertOk();

    foreach (['user', 'uploader'] as $role) {
        $this->actingAs(TestUser::factory()->create(['role' => $role]))->get('/bosun-ping')->assertOk();
    }

    expect(StaffPresence::query()->count())->toBe(0);
});

it('stamps at most once per interval', function () {
    $staff = TestUser::factory()->moderator()->create();

    $this->travelTo(now()->startOfMinute());
    $this->actingAs($staff)->get('/bosun-ping');
    $first = StaffPresence::query()->find($staff->id)->last_seen_at;

    $this->travel(2)->minutes();
    $this->actingAs($staff)->get('/bosun-ping');
    expect(StaffPresence::query()->find($staff->id)->last_seen_at->equalTo($first))->toBeTrue();

    $this->travel(4)->minutes();
    $this->actingAs($staff)->get('/bosun-ping');
    expect(StaffPresence::query()->find($staff->id)->last_seen_at->greaterThan($first))->toBeTrue();
});

it('stamps nobody when presence is off', function () {
    config(['bosun.presence.enabled' => false]);

    $this->actingAs(TestUser::factory()->admin()->create())->get('/bosun-ping');

    expect(StaffPresence::query()->count())->toBe(0);
});
