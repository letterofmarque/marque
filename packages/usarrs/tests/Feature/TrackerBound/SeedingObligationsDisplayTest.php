<?php

declare(strict_types=1);

// Job #256 step 5: a member sees what they owe seeding on under the tracker's
// hit-and-run rule: on /profile/stats in full, and as a count on the
// dashboard's Tracker Stats panel.

use Illuminate\Support\Carbon;
use Marque\Trove\Contracts\SeedingObligationsInterface;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Enums\ObligationState;
use Marque\Trove\Support\SeedingObligation;
use Marque\Trove\Support\TrackerStats;
use Marque\Usarrs\Tests\FakeSeedingObligations;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    app(TrackerStatsInterface::class)->stats[$this->user->id] = new TrackerStats(uploaded: 0, downloaded: 0, seedtime: 0);

    $this->obligations = new FakeSeedingObligations;
    app()->instance(SeedingObligationsInterface::class, $this->obligations);
});

function owes(string $name, int $seededHours, ObligationState $state = ObligationState::Open, ?string $until = null): SeedingObligation
{
    return new SeedingObligation(
        torrentId: crc32($name),
        torrentName: $name,
        seededSeconds: $seededHours * 3600,
        requiredSeconds: 72 * 3600,
        ratio: 0.25,
        requiredRatio: 1.0,
        state: $state,
        until: $until === null ? null : Carbon::parse($until),
    );
}

it('lists what the member owes on the stats page, with progress and strikes', function () {
    $this->obligations->list = [
        owes('Struck One', 2, ObligationState::Struck, '2026-11-16 00:00:00'),
        owes('Warned One', 10, ObligationState::Prewarned),
        owes('Open One', 31),
    ];

    $this->actingAs($this->user)->get(route('profile.stats'))
        ->assertOk()
        ->assertSee('Seeding owed')
        ->assertSee('Struck One')
        ->assertSee('Hit-and-run strike')
        ->assertSee('Mon, Nov 16, 2026')
        ->assertSee('Warned One')
        ->assertSee('Open One')
        ->assertSee('1d 7h of 3d 0h seeded')
        ->assertSee('ratio 0.25 of 1.00');
});

it('shows the count on the dashboard panel, linking to the full list', function () {
    $this->obligations->list = [owes('A', 1, ObligationState::Struck, '2026-11-16'), owes('B', 2)];

    $this->actingAs($this->user)->get(route('dashboard.index'))
        ->assertSee('Seeding owed on 2 torrents')
        ->assertSee('1 hit-and-run strike')
        ->assertSee(route('profile.stats'));
});

it('says nothing when nothing is owed, or no tracker keeps obligations', function () {
    $this->actingAs($this->user)->get(route('profile.stats'))->assertDontSee('Seeding owed');
    $this->actingAs($this->user)->get(route('dashboard.index'))->assertDontSee('Seeding owed');

    app()->forgetInstance(SeedingObligationsInterface::class);
    app()->offsetUnset(SeedingObligationsInterface::class);

    $this->actingAs($this->user)->get(route('profile.stats'))->assertOk()->assertDontSee('Seeding owed');
});
