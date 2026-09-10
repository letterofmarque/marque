<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Marque\Deck\View\Components\Navigation;
use Marque\Trove\Registry\NavItem;
use Marque\Trove\Registry\NavRegistry;

describe('navigation rendering', function () {
    it('renders registered entries as links in the real blade', function () {
        Route::get('probe', fn () => 'ok')->name('probe.index');

        app(NavRegistry::class)->register(new NavItem('probe', 'Probe Link', 'probe.index'));

        Livewire::test(Navigation::class)
            ->assertSee('Probe Link')
            ->assertSee('/probe');
    });

    // The blade iterates NavItem objects now, where it used to index arrays.
    // A property-access mistake there would not show up in the component's own
    // unit tests — only when the template actually runs.
    it('renders an empty nav without erroring', function () {
        Livewire::test(Navigation::class)->assertOk();
    });
});
