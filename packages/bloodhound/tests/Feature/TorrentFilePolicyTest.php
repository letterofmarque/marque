<?php

declare(strict_types=1);

use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Enums\PrivateFlag;

// #10947: bloodhound tells trove what a download needs (the member's own
// announce URL) and what uploads must be (private, by default).

it('binds the torrent file policy', function () {
    expect(app()->bound(TorrentFilePolicyInterface::class))->toBeTrue();
});

it('builds the member\'s announce URL with the key in the path', function () {
    $user = makeTrackerUser('aaaabbbbccccddddeeeeffffgggghhhh', 'path@example.com');

    expect(app(TorrentFilePolicyInterface::class)->announceUrlFor($user))
        ->toBe(url('/announce/aaaabbbbccccddddeeeeffffgggghhhh'));
});

it('has no announce URL for a guest or for a member with no key yet', function () {
    $keyless = TestUser::create(['name' => 'K', 'email' => 'keyless@example.com', 'password' => 'password']);
    AnnounceKey::query()->where('user_id', $keyless->getKey())->delete();

    $policy = app(TorrentFilePolicyInterface::class);

    expect($policy->announceUrlFor(null))->toBeNull()
        ->and($policy->announceUrlFor($keyless))->toBeNull();
});

it('requires private torrents by default', function () {
    expect(app(TorrentFilePolicyInterface::class)->privateFlag())->toBe(PrivateFlag::Require);
});

it('takes the private-flag rule from config, and a typo falls back to require', function (string $value, PrivateFlag $expected) {
    config(['bloodhound.uploads.private_flag' => $value]);

    expect(app(TorrentFilePolicyInterface::class)->privateFlag())->toBe($expected);
})->with([
    'allow' => ['allow', PrivateFlag::Allow],
    'warn_if_public' => ['warn_if_public', PrivateFlag::WarnIfPublic],
    'disallow' => ['disallow', PrivateFlag::Disallow],
    'typo' => ['requier', PrivateFlag::Require],
]);
