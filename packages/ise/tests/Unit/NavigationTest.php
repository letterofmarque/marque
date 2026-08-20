<?php

declare(strict_types=1);

use Marque\Ise\View\Components\Navigation;

describe('Navigation Component', function () {
    it('can be resolved from container', function () {
        $component = $this->app->make(Navigation::class);

        expect($component)->toBeInstanceOf(Navigation::class);
    });

    it('uses app name from config', function () {
        config()->set('ise.app_name', 'TestTracker');

        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect($component->appName)->toBe('TestTracker');
    });

    it('returns empty nav items when no packages installed', function () {
        $component = $this->app->make(Navigation::class);
        $component->mount();

        expect($component->items)->toBeEmpty();
    });
});
