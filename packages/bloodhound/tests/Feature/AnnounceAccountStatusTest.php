<?php

declare(strict_types=1);

// Found by the Job #141 security review. isUserEnabled() asked
// property_exists() about `enabled` and `status`, which is always false for an
// Eloquent attribute, so it fell through to "enabled" for everyone: a banned
// or disabled user kept announcing with their key.

use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;

function announceAs(object $test, array $attributes): ?string
{
    $user = TestUser::create(['name' => 'Peer', 'email' => 'peer@example.com', 'password' => 'password', ...$attributes]);
    issueAnnounceKey($user, 'aaaabbbbccccddddeeeeffffgggghhhh');

    $response = $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/aaaabbbbccccddddeeeeffffgggghhhh');

    return Bencode::decode($response->getContent())['failure reason'] ?? null;
}

it('refuses a user whose account is disabled by the enabled column', function () {
    expect(announceAs($this, ['enabled' => false]))->toBe('Account disabled');
});

it('refuses a user whose status marks them inactive', function (string $status) {
    expect(announceAs($this, ['status' => $status]))->toBe('Account disabled');
})->with(['banned', 'disabled', 'pending']);

it('lets an active user through to the request checks', function (?string $status) {
    // Missing announce parameters is the next thing to fail, so reaching it
    // means the account check passed.
    expect(announceAs($this, ['status' => $status]))->not->toBe('Account disabled');
})->with(['active', 'enabled', null]);
