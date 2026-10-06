<?php

declare(strict_types=1);

use Marque\Trove\Contracts\TorrentFilePolicyInterface;

// Query-key mode (/announce.php?passkey=…): the download's announce URL has to
// carry the key the same way the tracker reads it (#10947).
it('puts the key in the query string when the tracker reads it from there', function () {
    $user = makeTrackerUser('aaaabbbbccccddddeeeeffffgggghhhh', 'query@example.com');

    expect(app(TorrentFilePolicyInterface::class)->announceUrlFor($user))
        ->toBe(url('/announce.php').'?passkey=aaaabbbbccccddddeeeeffffgggghhhh');
});
