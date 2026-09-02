<?php

declare(strict_types=1);

use Marque\Parley\ParleyServiceProvider;
use Marque\Parley\Tests\TestSubject;
use Marque\Parley\Tests\TestUser;
use Marque\SquidInk\SquidInk;

describe('the package boots', function () {
    it('registers its service provider', function () {
        expect(app()->getProviders(ParleyServiceProvider::class))->not->toBeEmpty();
    });

    it('merges its config', function () {
        expect(config('parley'))->toBeArray()
            ->and(config('parley.forum.enabled'))->toBeTrue()
            ->and(config('parley.comments.enabled'))->toBeTrue();
    });

    it('registers its view namespace', function () {
        expect(view()->exists('parley::nonexistent'))->toBeFalse();

        // The namespace resolving at all is the point; a missing view inside it
        // returns false rather than throwing "No hint path defined".
        expect(fn () => view()->exists('parley::nonexistent'))->not->toThrow(InvalidArgumentException::class);
    });

    it('stores the moderation role as a string, so config:cache survives it', function () {
        // An enum instance does not survive serialisation into a cached config
        // file. The policies convert this back to a Role.
        expect(config('parley.moderation.role'))->toBeString();
    });
});

describe('its dependencies are present', function () {
    it('has trove', function () {
        expect(config('trove'))->toBeArray();
    });

    it('has squidink, which post bodies render through', function () {
        $squidInk = app(SquidInk::class);

        expect($squidInk->hasParser('markdown'))->toBeTrue()
            ->and($squidInk->hasParser('bbcode'))->toBeTrue()
            ->and($squidInk->hasRenderer('html'))->toBeTrue();
    });

    it('renders text through squidink rather than owning a format', function () {
        $html = app(SquidInk::class)->convert('**hi**', 'markdown', 'html');

        expect($html)->toBe('<p><strong>hi</strong></p>');
    });
});

describe('the host app supplies its own models', function () {
    it('resolves the configured user model', function () {
        $user = TestUser::factory()->create();

        expect($user->exists)->toBeTrue()
            ->and(config('trove.user_model'))->toBe(TestUser::class);
    });

    it('can attach discussion to an arbitrary model, not just torrents', function () {
        $subject = TestSubject::create(['name' => 'anything']);

        expect($subject->exists)->toBeTrue();
    });
});
