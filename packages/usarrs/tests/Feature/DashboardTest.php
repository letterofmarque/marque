<?php

declare(strict_types=1);

// Spec #118: the dashboard assembles panels other packages register. usarrs
// owns the page; it does not own most of what appears on it.
//
// This file runs with no tracker bound, so usarrs registers none of its tracker
// panels — which is what makes the zero-panels case below honest rather than
// contrived. The tracker-bound half is Feature/TrackerBound.

use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Registry\DashboardPanel;
use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();

    // A stand-in panel body. The registry names a Livewire component, so the
    // dashboard's render path must genuinely resolve and mount one — asserting
    // on the label alone would leave that path untested until a real tenant
    // package arrives in CP3.
    Livewire::component('usarrs-dashboard-test-panel', new class extends LivewireComponent
    {
        public function render()
        {
            return '<div>panel body</div>';
        }
    });
});

// CP3: usarrs registers the tracker panels only when a tracker is bound. This
// TestCase binds none, so neither may appear — the bound half is in
// Feature/TrackerBound, which needs the binding in place before boot.
test('no tracker panels register when no tracker is bound', function () {
    $registry = app(DashboardPanelRegistry::class);

    expect(app()->bound(TrackerStatsInterface::class))->toBeFalse()
        ->and($registry->find('usarrs-tracker-stats'))->toBeNull()
        ->and($registry->find('usarrs-announce-key'))->toBeNull();
});

test('dashboard requires authentication', function () {
    $this->get(route('dashboard.index'))->assertRedirect();
});

test('authenticated user can view the dashboard', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk();
});

// Criterion 2. The rejected design registered the route only when panels
// existed, which makes the route table install-dependent — route('dashboard.index')
// safe on one install and fatal on another, so every consumer linking to it
// would need a Route::has() guard.
test('the route is registered even with no panels at all', function () {
    expect(app(DashboardPanelRegistry::class)->all())->toBeEmpty();

    expect(fn () => route('dashboard.index'))->not->toThrow(Exception::class);

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk();
});

// Criterion 7. An empty grid looks like a broken page; saying so plainly is a
// better artifact, and it is a one-line guard.
test('an install with zero panels says so rather than rendering an empty grid', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('Nothing to show yet');
});

test('a registered panel renders on the page', function () {
    app(DashboardPanelRegistry::class)->register(
        new DashboardPanel('probe', 'Probe Panel', 'usarrs-dashboard-test-panel')
    );

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('Probe Panel')
        ->assertDontSee('Nothing to show yet');
});

test('panels render in position order', function () {
    $registry = app(DashboardPanelRegistry::class);
    $registry->register(new DashboardPanel('second', 'Second Panel', 'usarrs-dashboard-test-panel', position: 50));
    $registry->register(new DashboardPanel('first', 'First Panel', 'usarrs-dashboard-test-panel', position: 10));

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSeeInOrder(['First Panel', 'Second Panel']);
});

// The per-request mechanism: a panel whose closure declines for THIS user does
// not render, even though it is registered and its package is installed.
test('a panel whose visibility closure declines does not render', function () {
    app(DashboardPanelRegistry::class)->register(new DashboardPanel(
        'hidden',
        'Hidden Panel',
        'usarrs-dashboard-test-panel',
        visible: fn (?object $user): bool => false,
    ));

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertDontSee('Hidden Panel');
});

test('a panel hidden from this user still leaves the page reporting nothing to show', function () {
    // Registered but invisible is, from this user's point of view, no panel at
    // all — the empty-state message must not depend on the registry being empty.
    app(DashboardPanelRegistry::class)->register(new DashboardPanel(
        'hidden',
        'Hidden Panel',
        'usarrs-dashboard-test-panel',
        visible: fn (?object $user): bool => false,
    ));

    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('Nothing to show yet');
});
