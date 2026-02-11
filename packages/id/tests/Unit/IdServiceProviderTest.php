<?php

declare(strict_types=1);

describe('IdServiceProvider', function () {
    it('registers id config', function () {
        expect(config('id.app_name'))->toBe('Marque');
        expect(config('id.show_footer'))->toBeTrue();
        expect(config('id.theme'))->toBe('default');
    });

    it('registers id views namespace', function () {
        $finder = $this->app['view']->getFinder();
        $hints = $finder->getHints();

        expect($hints)->toHaveKey('id');
    });

    it('can resolve layout view', function () {
        $view = $this->app['view'];

        expect($view->exists('id::layouts.app'))->toBeTrue();
    });

    it('can resolve footer component view', function () {
        $view = $this->app['view'];

        expect($view->exists('id::components.footer'))->toBeTrue();
    });

    it('can resolve navigation component view', function () {
        $view = $this->app['view'];

        expect($view->exists('id::components.navigation'))->toBeTrue();
    });
});
