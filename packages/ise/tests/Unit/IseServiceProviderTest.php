<?php

declare(strict_types=1);

describe('IseServiceProvider', function () {
    it('registers ise config', function () {
        expect(config('ise.app_name'))->toBe('Marque');
        expect(config('ise.show_footer'))->toBeTrue();
        expect(config('ise.theme'))->toBe('default');
    });

    it('registers ise views namespace', function () {
        $finder = $this->app['view']->getFinder();
        $hints = $finder->getHints();

        expect($hints)->toHaveKey('ise');
    });

    it('can resolve layout view', function () {
        $view = $this->app['view'];

        expect($view->exists('ise::layouts.app'))->toBeTrue();
    });

    it('can resolve footer component view', function () {
        $view = $this->app['view'];

        expect($view->exists('ise::components.footer'))->toBeTrue();
    });

    it('can resolve navigation component view', function () {
        $view = $this->app['view'];

        expect($view->exists('ise::components.navigation'))->toBeTrue();
    });
});
