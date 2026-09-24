<?php

declare(strict_types=1);

// Build #113 CP3 (Spec #119). usarrs asks trove's TrackerStatsInterface for
// tracker figures instead of probing the User model for bloodhound's columns
// and methods. usarrs does not depend on bloodhound, so:
//
//   - with nothing bound, there is no tracker: the sections are ABSENT, even
//     when the users table happens to carry tracker-shaped columns
//   - with a tracker bound, what renders is what the tracker says — which the
//     fake deliberately makes different from the users table, so a test can
//     tell the two sources apart

use Livewire\Livewire;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Support\TrackerStats;
use Marque\Usarrs\Livewire\Profile\AnnounceKeyManagement;
use Marque\Usarrs\Tests\FakeTrackerStats;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    // Tracker-shaped data sitting in the users table. None of it should ever
    // reach the page: it is bloodhound's storage, not usarrs' to read.
    $this->user = TestUser::factory()->create([
        'announce_key' => 'tablekeytablekeytablekeytablekey',
        'uploaded' => 111,
    ]);
    $this->admin = TestUser::factory()->admin()->create();
});

describe('with no tracker installed', function () {
    it('binds nothing, since usarrs does not require a tracker', function () {
        expect(app()->bound(TrackerStatsInterface::class))->toBeFalse();
    });

    it('shows no stats and no announce key on the stats page', function () {
        $this->actingAs($this->user)
            ->get(route('profile.stats'))
            ->assertOk()
            ->assertDontSee('Uploaded')
            ->assertDontSee('Announce Key')
            ->assertDontSee('tablekeytablekeytablekeytablekey');
    });

    it('does not link to tracker stats from the profile', function () {
        $this->actingAs($this->user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertDontSee(route('profile.stats'));
    });

    it('shows no tracker figures on the admin user page', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $this->user->id))
            ->assertOk()
            ->assertDontSee('Uploaded');
    });

    it('refuses to regenerate a key there is no tracker to issue', function () {
        $this->actingAs($this->user);

        Livewire::test(AnnounceKeyManagement::class)
            ->call('regenerateAnnounceKey')
            ->assertNotFound();

        expect($this->user->fresh()->announce_key)->toBe('tablekeytablekeytablekeytablekey');
    });
});

describe('with a tracker bound', function () {
    beforeEach(function () {
        $this->tracker = new FakeTrackerStats;
        $this->tracker->stats[$this->user->id] = new TrackerStats(
            uploaded: 3 * 1024 ** 3,
            downloaded: 2 * 1024 ** 3,
            seedtime: 0,
        );
        $this->tracker->keys[$this->user->id] = 'trackerkeytrackerkeytrackerkey00';

        app()->instance(TrackerStatsInterface::class, $this->tracker);
    });

    it('renders the tracker\'s figures, not the users table', function () {
        $this->actingAs($this->user)
            ->get(route('profile.stats'))
            ->assertOk()
            ->assertSee('3 GB')
            ->assertSee('2 GB')
            ->assertSee('1.50');
    });

    it('shows the key the tracker reports, not the one in the users table', function () {
        $this->actingAs($this->user)
            ->get(route('profile.stats'))
            ->assertSee('trackerkeytrackerkeytrackerkey00')
            ->assertDontSee('tablekeytablekeytablekeytablekey');
    });

    it('omits the key section when the tracker has no key for the user', function () {
        unset($this->tracker->keys[$this->user->id]);

        $this->actingAs($this->user)
            ->get(route('profile.stats'))
            ->assertDontSee('Announce Key');
    });

    // getRatio() returned null for an infinite ratio and the view printed it
    // raw, so a user who had downloaded nothing saw an empty box.
    it('renders an infinite ratio as infinite, not blank or zero', function () {
        $this->tracker->stats[$this->user->id] = new TrackerStats(uploaded: 500, downloaded: 0, seedtime: 0);

        $this->actingAs($this->user)
            ->get(route('profile.stats'))
            ->assertSee('∞')
            ->assertDontSee('0.00');
    });

    it('links to tracker stats from the profile', function () {
        $this->actingAs($this->user)
            ->get(route('profile.show'))
            ->assertSee(route('profile.stats'));
    });

    it('shows the target user\'s tracker figures to an admin', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $this->user->id))
            ->assertOk()
            ->assertSee('3 GB')
            ->assertSee('1.50');
    });

    it('regenerates through the tracker and writes nothing itself', function () {
        $this->actingAs($this->user);

        Livewire::test(AnnounceKeyManagement::class)->call('regenerateAnnounceKey');

        expect($this->tracker->regenerated)->toBe([$this->user->id])
            ->and($this->user->fresh()->announce_key)->toBe('tablekeytablekeytablekeytablekey');
    });

    it('still refuses to regenerate when regeneration is disabled', function () {
        config()->set('usarrs.profile.allow_announce_key_regen', false);
        $this->actingAs($this->user);

        Livewire::test(AnnounceKeyManagement::class)
            ->call('regenerateAnnounceKey')
            ->assertForbidden();

        expect($this->tracker->regenerated)->toBe([]);
    });
});
