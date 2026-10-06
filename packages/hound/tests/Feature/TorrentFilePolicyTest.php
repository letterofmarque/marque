<?php

declare(strict_types=1);

use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;

// #10947: a public tracker's downloads point at its own open announce URL, for
// guests and members alike, and its uploads must NOT be private: the flag
// turns off DHT and peer exchange, which a public swarm wants.

it('binds the torrent file policy', function () {
    expect(app()->bound(TorrentFilePolicyInterface::class))->toBeTrue();
});

it('gives everyone the open announce URL, guests and members alike', function () {
    $policy = app(TorrentFilePolicyInterface::class);

    expect($policy->announceUrlFor(null))->toBe(url('/announce'))
        ->and($policy->announceUrlFor(Mockery::mock(UserInterface::class)))->toBe(url('/announce'));
});

it('disallows private torrents by default, overridable, with a typo falling back to disallow', function (?string $value, PrivateFlag $expected) {
    if ($value !== null) {
        config(['hound.uploads.private_flag' => $value]);
    }

    expect(app(TorrentFilePolicyInterface::class)->privateFlag())->toBe($expected);
})->with([
    'default' => [null, PrivateFlag::Disallow],
    'allow' => ['allow', PrivateFlag::Allow],
    'warn_if_private' => ['warn_if_private', PrivateFlag::WarnIfPrivate],
    'typo' => ['dissallow', PrivateFlag::Disallow],
]);
