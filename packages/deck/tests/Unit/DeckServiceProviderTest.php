<?php

declare(strict_types=1);

describe('DeckServiceProvider', function () {
    it('registers ise config', function () {
        expect(config('deck.app_name'))->toBe('Marque');
        expect(config('deck.show_footer'))->toBeTrue();
        expect(config('deck.theme'))->toBe('default');
    });

    it('registers ise views namespace', function () {
        $finder = $this->app['view']->getFinder();
        $hints = $finder->getHints();

        expect($hints)->toHaveKey('deck');
    });

    it('can resolve layout view', function () {
        $view = $this->app['view'];

        expect($view->exists('deck::layouts.app'))->toBeTrue();
    });

    it('can resolve footer component view', function () {
        $view = $this->app['view'];

        expect($view->exists('deck::components.footer'))->toBeTrue();
    });

    it('can resolve navigation component view', function () {
        $view = $this->app['view'];

        expect($view->exists('deck::components.navigation'))->toBeTrue();
    });

    it('actually renders the layout without a missing-component error', function () {
        // Regression test for job #10602 Gap 5: the shipped layout template
        // referenced the pre-rename <livewire:id-navigation /> tag while
        // DeckServiceProvider registered the component as deck-navigation —
        // exists()-only checks above never caught it because a view can
        // exist as a file and still fail to compile/render. Render, don't
        // just check existence.
        //
        // withoutVite() is needed because this test deliberately renders
        // ise's OWN shipped layout — unlike every downstream package
        // (guise, usarrs), whose tests render a test-local stub layout
        // instead specifically to avoid needing a Vite build in test infra.
        // That's exactly why this bug reached a tagged release without any
        // suite catching it: nothing was actually rendering this file.
        $this->withoutVite();

        $html = view('deck::layouts.app', ['slot' => 'content'])->render();

        expect($html)->toBeString();
    });
});
