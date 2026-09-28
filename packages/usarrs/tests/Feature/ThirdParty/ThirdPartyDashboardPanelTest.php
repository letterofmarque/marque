<?php

declare(strict_types=1);

// Spec #118 criterion 5 (Build #108 CP5), the rendering half. A panel from a
// package Marque has never heard of renders on the dashboard exactly as a
// first-party one does: same card, same heading, ordered among usarrs' own
// panels by the position its package chose. usarrs gives its own panels no
// privilege a stranger cannot have — which is what keeps this public API.
//
// The other half — a package contributing a panel boots cleanly with usarrs
// absent — is proven in trove's suite, whose vendor has no usarrs at all.

use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();

    // Two first-party panels to sit either side of the stranger's (30, 40).
    config()->set('usarrs.two_factor.enabled', true);
    config()->set('usarrs.invites.enabled', true);
});

it('registers the stranger\'s panel alongside usarrs\' own', function () {
    expect(array_keys(app(DashboardPanelRegistry::class)->all()))
        ->toContain('usarrs-security', 'acme-stats', 'usarrs-invites');
});

it('renders the stranger\'s panel body on the dashboard', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('Acme Stats')
        ->assertSee('42 widgets frobbed');
});

it('orders it among the first-party panels by the position it chose', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard.index'))
        ->assertSeeInOrder(['Account Security', 'Acme Stats', 'Invites']);
});

it('wraps it in the same card and heading as a first-party panel', function () {
    $html = $this->actingAs($this->user)->get(route('dashboard.index'))->getContent();

    // The markup from the card's opening tag up to the panel label, for one
    // panel of each kind. Identical strings mean the page drew no distinction.
    // Livewire interleaves <!--[if BLOCK]--> markers, so whitespace between
    // tags may also be those comments.
    $gap = '(?:\s|<!--.*?-->)*';

    $cardFor = function (string $label) use ($html, $gap): string {
        expect(preg_match('/<div class="rounded-xl[^"]*">'.$gap.'<div class="mb-4[^"]*">'.$gap.'<h\d[^>]*>\s*'.preg_quote($label, '/').'\s*</', $html, $m))
            ->toBe(1, "no panel card found for [{$label}]");

        return str_replace($label, 'LABEL', $m[0]);
    };

    expect($cardFor('Acme Stats'))->toBe($cardFor('Invites'));
});
