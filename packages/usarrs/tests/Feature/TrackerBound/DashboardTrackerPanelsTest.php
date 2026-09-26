<?php

declare(strict_types=1);

// Build #108 CP3 (Spec #118, rewritten after Spec #119). usarrs renders the
// tracker's panels from TrackerStatsInterface, and registers them only when a
// tracker is bound. That is the one capability check — ratio_mode is not one,
// because nothing reads it (job #10732) and the figures are identical in every
// mode.
//
// This directory runs with the fake bound BEFORE boot (TrackerBoundTestCase).
// The unbound half lives in DashboardTest, on the ordinary TestCase.

use Livewire\Livewire;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Trove\Support\TrackerStats;
use Marque\Usarrs\Livewire\Dashboard\AnnounceKeyPanel;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    // Tracker-shaped data in the users table, as in TrackerStatsContractTest:
    // none of it should reach the dashboard, because it is not usarrs' to read.
    $this->user = TestUser::factory()->create([
        'announce_key' => 'tablekeytablekeytablekeytablekey',
        'uploaded' => 111,
    ]);

    $this->tracker = app(TrackerStatsInterface::class);
    $this->tracker->stats[$this->user->id] = new TrackerStats(
        uploaded: 3 * 1024 ** 3,
        downloaded: 2 * 1024 ** 3,
        seedtime: 0,
    );
    $this->tracker->keys[$this->user->id] = 'trackerkeytrackerkeytrackerkey00';
});

it('registers both tracker panels when a tracker is bound', function () {
    $registry = app(DashboardPanelRegistry::class);

    expect($registry->find('usarrs-tracker-stats'))->not->toBeNull()
        ->and($registry->find('usarrs-announce-key'))->not->toBeNull();
});

it('renders the tracker\'s figures on the dashboard, not the users table', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('3 GB')
        ->assertSee('2 GB')
        ->assertSee('1.50')
        ->assertDontSee('Nothing to show yet');
});

it('renders an infinite ratio as infinite, never 0.00', function () {
    $this->tracker->stats[$this->user->id] = new TrackerStats(uploaded: 500, downloaded: 0, seedtime: 0);

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertSee('∞')
        ->assertDontSee('0.00');
});

it('shows the key the tracker reports, not the one in the users table', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertSee('trackerkeytrackerkeytrackerkey00')
        ->assertDontSee('tablekeytablekeytablekeytablekey');
});

it('links the stats panel through to the full stats page', function () {
    // Spec #118 OQ3: the dashboard coexists with /profile/stats in v1.
    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertSee(route('profile.stats'));
});

// Per-request visibility: the tracker exists, but has nothing for THIS user.
it('omits the stats panel for a user the tracker keeps no figures for', function () {
    unset($this->tracker->stats[$this->user->id]);

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertDontSee('Tracker Stats')
        ->assertSee('Announce Key');
});

it('omits the announce key panel for a user with no key', function () {
    unset($this->tracker->keys[$this->user->id]);

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertDontSee('Announce Key')
        ->assertSee('Tracker Stats');
});

it('omits the announce key panel when the operator hides announce keys', function () {
    config()->set('usarrs.profile.show_announce_key', false);

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertDontSee('Announce Key')
        ->assertDontSee('trackerkeytrackerkeytrackerkey00');
});

it('regenerates from the panel through the contract and writes nothing itself', function () {
    $this->actingAs($this->user);

    Livewire::test(AnnounceKeyPanel::class)
        ->assertSee('trackerkeytrackerkeytrackerkey00')
        ->call('regenerateAnnounceKey')
        ->assertSee($this->tracker->keys[$this->user->id]);

    expect($this->tracker->regenerated)->toBe([$this->user->id])
        ->and($this->user->fresh()->announce_key)->toBe('tablekeytablekeytablekeytablekey');
});

it('keeps the confirm dialog on the panel\'s regenerate button', function () {
    $this->actingAs($this->user);

    Livewire::test(AnnounceKeyPanel::class)
        ->assertSeeHtml('wire:confirm');
});

it('refuses to regenerate from the panel when regeneration is disabled', function () {
    config()->set('usarrs.profile.allow_announce_key_regen', false);
    $this->actingAs($this->user);

    Livewire::test(AnnounceKeyPanel::class)
        ->assertDontSee('Regenerate')
        ->call('regenerateAnnounceKey')
        ->assertForbidden();

    expect($this->tracker->regenerated)->toBe([]);
});

// The stats page now renders from the same partials. Guard that the extraction
// did not change it — TrackerStatsContractTest covers its behaviour in full.
it('leaves /profile/stats rendering the same figures and key', function () {
    $this->actingAs($this->user)
        ->get(route('profile.stats'))
        ->assertOk()
        ->assertSee('3 GB')
        ->assertSee('1.50')
        ->assertSee('trackerkeytrackerkeytrackerkey00');
});
