<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Marque\Trove\Support\Seedbox;

it('knows whether it is approved', function () {
    $approved = new Seedbox(1, 2, '203.0.113.7/32', 'box', Seedbox::APPROVED, CarbonImmutable::now(), approvedAt: CarbonImmutable::now(), approvedBy: 9);
    $pending = new Seedbox(1, 2, '203.0.113.7/32', null, Seedbox::PENDING, CarbonImmutable::now());

    expect($approved->isApproved())->toBeTrue()
        ->and($pending->isApproved())->toBeFalse();
});

it('refuses a status that does not exist', function () {
    new Seedbox(1, 2, '203.0.113.7/32', null, 'maybe', CarbonImmutable::now());
})->throws(InvalidArgumentException::class);
