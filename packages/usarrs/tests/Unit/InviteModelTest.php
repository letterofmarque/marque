<?php

declare(strict_types=1);

use Marque\Usarrs\Enums\InviteStatus;
use Marque\Usarrs\Models\Invite;

describe('Invite Model', function () {
    it('generates a 32 character code', function () {
        $code = Invite::generateCode();

        expect($code)->toHaveLength(32)
            ->and(ctype_xdigit($code))->toBeTrue();
    });

    it('reports validity correctly', function () {
        $valid = new Invite([
            'status' => InviteStatus::Pending->value,
            'expires_at' => now()->addDay(),
        ]);
        expect($valid->isValid())->toBeTrue();

        $used = new Invite([
            'status' => InviteStatus::Used->value,
            'expires_at' => now()->addDay(),
        ]);
        expect($used->isValid())->toBeFalse();

        $expired = new Invite([
            'status' => InviteStatus::Pending->value,
            'expires_at' => now()->subDay(),
        ]);
        expect($expired->isValid())->toBeFalse();
    });

    it('detects expiration', function () {
        $notExpired = new Invite(['expires_at' => now()->addDay()]);
        expect($notExpired->isExpired())->toBeFalse();

        $expired = new Invite(['expires_at' => now()->subDay()]);
        expect($expired->isExpired())->toBeTrue();

        $noExpiry = new Invite(['expires_at' => null]);
        expect($noExpiry->isExpired())->toBeFalse();
    });
});
