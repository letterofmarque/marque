<?php

declare(strict_types=1);

// Spec #118 criterion 5 (Build #108 CP5). A package that contributes a
// dashboard panel boots and behaves identically when nothing that renders
// panels is installed. This suite is the proof by construction: trove's own
// vendor has neither usarrs nor Livewire, and the fixture provider registers
// its panel from boot() the way a real package would.

use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Trove\Tests\TestUser;

it('runs with neither the dashboard nor Livewire installed', function () {
    // Asserted rather than assumed: if trove ever grew a dev dependency on
    // either, every other test here would still pass while proving nothing.
    expect(class_exists('Marque\\Usarrs\\UsarrsServiceProvider'))->toBeFalse()
        ->and(class_exists('Livewire\\Livewire'))->toBeFalse();
});

it('keeps the panel a third-party provider registered while booting', function () {
    $panel = app(DashboardPanelRegistry::class)->find('acme-stats');

    expect($panel)->not->toBeNull()
        ->and($panel->label)->toBe('Acme Stats')
        ->and($panel->component)->toBe('acme-stats-panel')
        ->and($panel->position)->toBe(35);
});

it('answers the panel\'s visibility with no dashboard to ask it', function () {
    $registry = app(DashboardPanelRegistry::class);
    $user = TestUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);

    expect($registry->visibleTo($user))->toHaveKey('acme-stats')
        ->and($registry->visibleTo(null))->not->toHaveKey('acme-stats');
});
