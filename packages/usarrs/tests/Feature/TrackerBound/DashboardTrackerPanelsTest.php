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

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Trove\Support\TrackerStanding;
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

// #11070 item 6. The panel used to hide for a user with no key, and the
// partial's "Generate announce key" button behind it was only reachable on
// /profile/stats. A user imported verified, or one whose key was never issued,
// saw nothing on the dashboard at all.
it('offers a verified user with no key the button to get one', function () {
    unset($this->tracker->keys[$this->user->id]);

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('Announce Key')
        ->assertSee('Generate announce key');
});

it('tells an unverified user to verify, which is what the dashboard admits them for', function () {
    $unverified = TestUser::factory()->unverified()->create();

    $this->actingAs($unverified)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('Announce Key')
        ->assertSee('Verify your email address to get an announce key.')
        ->assertDontSee('Generate announce key');
});

it('issues the first key from the dashboard panel', function () {
    unset($this->tracker->keys[$this->user->id]);
    $this->actingAs($this->user);

    Livewire::test(AnnounceKeyPanel::class)
        ->call('regenerateAnnounceKey')
        ->assertSee($this->tracker->keys[$this->user->id]);
});

// #11070 item 5: 9.0 credits seed and leech time per announce, and the panel
// showed neither.
it('shows seed time and leech time beside the byte counts', function () {
    $this->tracker->stats[$this->user->id] = new TrackerStats(uploaded: 0, downloaded: 0, seedtime: 93_784, leechtime: 7_260);

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertSee('Seed time')
        ->assertSee('1d 2h')
        ->assertSee('Leech time')
        ->assertSee('2h 1m');
});

// #10732: where the member stands against the tracker's ratio rule, said where
// they look at their ratio. Ok says nothing: no news is good news.
describe('the member\'s standing', function () {
    it('shows a watch, with its deadline and what to do', function () {
        $this->tracker->stats[$this->user->id] = new TrackerStats(uploaded: 1, downloaded: 10, seedtime: 0, standing: TrackerStanding::watch(
            Carbon::parse('2026-11-15 10:00:00'),
            'Your ratio is 0.10 and this tracker asks for at least 0.50. Seed to raise it by Sun, Nov 15, 2026, or new downloads will be paused until you do.',
        ));

        foreach ([route('dashboard.index'), route('profile.stats')] as $page) {
            $this->actingAs($this->user)->get($page)
                ->assertSee('Ratio watch')
                ->assertSee('Seed to raise it by Sun, Nov 15, 2026');
        }
    });

    // A tracker may report a watch with no wording of its own; the deadline
    // is still the thing the member needs.
    it('shows a watch\'s deadline when the tracker gives no reason', function () {
        $this->tracker->stats[$this->user->id] = new TrackerStats(uploaded: 1, downloaded: 10, seedtime: 0, standing: TrackerStanding::watch(
            Carbon::parse('2026-11-15 10:00:00'),
        ));

        $this->actingAs($this->user)->get(route('dashboard.index'))
            ->assertSee('Ratio watch')
            ->assertSee('Sun, Nov 15, 2026');
    });

    it('shows a restriction, with its reason', function () {
        $this->tracker->stats[$this->user->id] = new TrackerStats(uploaded: 1, downloaded: 10, seedtime: 0, standing: TrackerStanding::restricted(
            'New downloads are paused: your ratio is 0.10 and this tracker asks for at least 0.50. Keep seeding; downloads resume within the hour once it is back up.',
        ));

        $this->actingAs($this->user)->get(route('dashboard.index'))
            ->assertSee('Downloads paused')
            ->assertSee('Keep seeding; downloads resume within the hour once it is back up.');
    });

    it('says when the member is exempt', function () {
        $this->tracker->stats[$this->user->id] = new TrackerStats(uploaded: 1, downloaded: 10, seedtime: 0, standing: TrackerStanding::exempt());

        $this->actingAs($this->user)->get(route('dashboard.index'))
            ->assertSee('exempt from the ratio rule');
    });

    it('says nothing extra when the member is ok', function () {
        $this->actingAs($this->user)->get(route('dashboard.index'))
            ->assertDontSee('Ratio watch')
            ->assertDontSee('Downloads paused')
            ->assertDontSee('exempt from the ratio rule');
    });
});

// #11070 item 7: in a narrow card the key and the Regenerate button sat in one
// row that never wrapped, and the button was clipped to "Rege".
it('lets the key row wrap and the key break, so a narrow card clips nothing', function () {
    $this->actingAs($this->user);

    Livewire::test(AnnounceKeyPanel::class)
        ->assertSeeHtml('flex-wrap')
        ->assertSeeHtml('break-all');
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
